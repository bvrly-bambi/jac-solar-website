#!/usr/bin/env python3
"""
Static safety checks for the Free Quote V1 backend.

Runs without PHP, without a database, and without any credentials.
Verifies the properties that are easy to regress during future edits:

  * no hard-coded secrets
  * no string-interpolated SQL
  * every SQL statement goes through prepare()
  * no server paths or driver messages in client-facing output
  * required security controls are present
  * the applied Phase 1 migration is unchanged

Usage:  python3 tests/static_analysis.py
Exit :  0 when all checks pass, 1 otherwise.
"""

from __future__ import annotations

import hashlib
import pathlib
import re
import sys

ROOT = pathlib.Path(__file__).resolve().parent.parent
API = ROOT / "api"
SRC = API / "src"

# SHA-256 of database/migrations/001_free_quote_v1_schema.sql as it exists on
# the remote branch (commit f0a5b05) and as already applied on Hostinger.
#
# Note: this differs from the file originally generated in Phase 1. The
# migration was tightened before it was executed — full_name VARCHAR(100),
# electricity_provider VARCHAR(150), duplicate_hash CHAR(64), and the upload
# metadata columns made NOT NULL. The applied version is authoritative and
# Phase 2 must not alter it.
PHASE1_MIGRATION_SHA256 = (
    "1b2067ec66fe60992a56e299f6d0e9d4afc68797397d938c59ca31ead8b5efdc"
)

failures: list[str] = []
passes: list[str] = []


def check(label: str, condition: bool, detail: str = "") -> None:
    if condition:
        passes.append(label)
        print(f"  PASS  {label}")
    else:
        failures.append(label)
        print(f"  FAIL  {label}" + (f" — {detail}" if detail else ""))


def php_files() -> list[pathlib.Path]:
    return sorted(API.rglob("*.php"))


def read(path: pathlib.Path) -> str:
    return path.read_text(encoding="utf-8")


def strip_comments(body: str) -> str:
    """Remove /* */ and // comments so prose is not mistaken for code."""
    body = re.sub(r"/\*.*?\*/", "", body, flags=re.DOTALL)
    body = re.sub(r"(?m)//.*$", "", body)
    return body


def string_literals(body: str) -> list[str]:
    """Return the contents of single- and double-quoted PHP string literals."""
    code = strip_comments(body)
    return [
        m.group(1) if m.group(1) is not None else m.group(2)
        for m in re.finditer(r"'([^'\\]*(?:\\.[^'\\]*)*)'|\"([^\"\\]*(?:\\.[^\"\\]*)*)\"", code)
    ]


def sql_literals(body: str) -> list[str]:
    """String literals that actually look like SQL statements."""
    statement = re.compile(
        r"^\s*(SELECT|INSERT\s+(INTO|IGNORE)|UPDATE\s+\w|DELETE\s+FROM)\b",
        re.IGNORECASE,
    )
    return [lit for lit in string_literals(body) if statement.search(lit)]


print("\n── Phase 1 migration integrity ──")

migration = ROOT / "database" / "migrations" / "001_free_quote_v1_schema.sql"
check("001 migration still present", migration.is_file())

if migration.is_file():
    digest = hashlib.sha256(migration.read_bytes()).hexdigest()
    check(
        "001 migration byte-identical to Phase 1",
        digest == PHASE1_MIGRATION_SHA256,
        f"expected {PHASE1_MIGRATION_SHA256[:16]}…, got {digest[:16]}…",
    )

extra_migrations = sorted(
    p.name for p in (ROOT / "database" / "migrations").glob("*.sql")
    if p.name != "001_free_quote_v1_schema.sql"
)
check(
    "no unexplained second migration",
    not extra_migrations,
    f"found {extra_migrations}",
)


print("\n── Secret scan ──")

SECRET_PATTERNS = [
    # An assignment whose right-hand side is a non-empty literal.
    (r"""['"]db_password['"]\s*=>\s*['"][^'"]+['"]""", "literal db_password"),
    (r"""['"]smtp_password['"]\s*=>\s*['"][^'"]+['"]""", "literal smtp_password"),
    (r"""\$password\s*=\s*['"][^'"]{3,}['"]""", "literal $password"),
    (r"""AKIA[0-9A-Z]{16}""", "AWS key"),
    (r"""-----BEGIN [A-Z ]*PRIVATE KEY-----""", "private key"),
]

secret_hits: list[str] = []
for path in php_files() + [ROOT / "config.example.php"]:
    if not path.is_file():
        continue
    body = read(path)
    for pattern, label in SECRET_PATTERNS:
        for match in re.finditer(pattern, body):
            secret_hits.append(f"{path.relative_to(ROOT)}: {label} -> {match.group(0)[:60]}")

check("no hard-coded secrets in PHP", not secret_hits, "; ".join(secret_hits))

example = ROOT / "config.example.php"
if example.is_file():
    body = read(example)
    check(
        "config.example.php passwords remain empty",
        re.search(r"""['"]db_password['"]\s*=>\s*''""", body) is not None
        and re.search(r"""['"]smtp_password['"]\s*=>\s*''""", body) is not None,
    )


print("\n── SQL safety ──")

sql_keywords = re.compile(r"\b(SELECT|INSERT|UPDATE|DELETE)\b", re.IGNORECASE)
interpolation = re.compile(r"""["'][^"'\n]*\b(SELECT|INSERT|UPDATE|DELETE)\b[^"'\n]*\$""", re.IGNORECASE)
concat_sql = re.compile(r"""\b(SELECT|INSERT|UPDATE|DELETE)\b[^;\n]{0,120}?['"]\s*\.\s*\$""", re.IGNORECASE)

interp_hits: list[str] = []
for path in php_files():
    body = read(path)
    for regex in (interpolation, concat_sql):
        for match in regex.finditer(body):
            interp_hits.append(f"{path.relative_to(ROOT)}: {match.group(0)[:70]}")

check("no interpolated or concatenated SQL", not interp_hits, "; ".join(interp_hits))

# Every file that contains a real SQL statement literal must call prepare().
# PHP method names such as QuoteRepository::insert() are not SQL, and prose in
# comments is stripped before this check runs.
missing_prepare: list[str] = []
total_sql_literals = 0

for path in php_files():
    body = read(path)
    literals = sql_literals(body)
    total_sql_literals += len(literals)

    if literals and "->prepare(" not in body:
        missing_prepare.append(
            f"{path.relative_to(ROOT)} ({len(literals)} statement(s))"
        )

check("all SQL uses prepared statements", not missing_prepare, "; ".join(missing_prepare))
check("SQL statement literals were actually found", total_sql_literals > 0,
      "the check would be vacuous otherwise")

# Every bound statement must use named placeholders, never inline values.
unbound: list[str] = []
for path in php_files():
    for literal in sql_literals(read(path)):
        if re.search(r"\bWHERE\b|\bVALUES\b|\bSET\b", literal, re.IGNORECASE):
            if ":" not in literal:
                unbound.append(f"{path.relative_to(ROOT)}: {literal[:60]}")

check("parameterised statements use named placeholders", not unbound, "; ".join(unbound))

# exec() is permitted only for the fixed time_zone statement.
exec_hits: list[str] = []
for path in php_files():
    for match in re.finditer(r"->exec\(([^)]*)\)", read(path)):
        argument = match.group(1)
        if "$" in argument:
            exec_hits.append(f"{path.relative_to(ROOT)}: {match.group(0)[:60]}")

check("no variables passed to PDO::exec", not exec_hits, "; ".join(exec_hits))

# query() with a variable is equally unsafe.
query_hits = [
    str(p.relative_to(ROOT))
    for p in php_files()
    if re.search(r"->query\([^)]*\$", read(p))
]
check("no variables passed to PDO::query", not query_hits, "; ".join(query_hits))


print("\n── Client-facing output safety ──")

response_php = SRC / "Response.php"
if response_php.is_file():
    body = read(response_php)
    check("Response sets JSON content type", "application/json" in body)
    check("Response sets no-store", "no-store" in body)
    check("Response sets nosniff", "X-Content-Type-Options" in body)

leak_terms = ["/home/u500192602", "getMessage()", "getTraceAsString", "__DIR__"]
leak_hits: list[str] = []

for path in php_files():
    body = read(path)
    relative = str(path.relative_to(ROOT))

    # Config.php legitimately names the external path; endpoints legitimately
    # use __DIR__ to require bootstrap.
    for term in leak_terms:
        if term not in body:
            continue
        if term == "/home/u500192602" and relative == "api/src/Config.php":
            continue
        if term == "__DIR__":
            continue
        if term == "getMessage()" and relative == "api/src/EmailService.php":
            # Passed to safeError() for database logging only, never echoed.
            continue
        leak_hits.append(f"{relative}: {term}")

check("no server paths or traces in output paths", not leak_hits, "; ".join(leak_hits))

# Nothing may echo an exception or a PDO message.
echo_hits = [
    str(p.relative_to(ROOT))
    for p in php_files()
    if re.search(r"echo\s+\$e\b|print_r\s*\(|var_dump\s*\(", read(p))
]
check("no echo/var_dump of exceptions", not echo_hits, "; ".join(echo_hits))


print("\n── Required security controls ──")

security_php = SRC / "Security.php"
if security_php.is_file():
    body = read(security_php)
    check("timing-safe CSRF comparison", "hash_equals(" in body)
    check("cryptographic token source", "random_bytes(" in body)
    check("session cookie Secure", "'secure'   => true" in body or "'secure' => true" in body)
    check("session cookie HttpOnly", "'httponly' => true" in body)
    check("session cookie SameSite=Lax", "'samesite' => 'Lax'" in body)
    check("strict session mode", "session.use_strict_mode" in body)
    check("client IP uses REMOTE_ADDR", "REMOTE_ADDR" in body)
    check(
        "X-Forwarded-For not used for client IP",
        "HTTP_X_FORWARDED_FOR" not in body,
    )

upload_php = SRC / "UploadService.php"
if upload_php.is_file():
    body = read(upload_php)
    check("real MIME via finfo", "finfo_file(" in body)
    check("image structure via getimagesize", "getimagesize(" in body)
    check("SHA-256 of temporary file", "hash_file('sha256'" in body)
    check("random stored filename", "random_bytes(" in body)
    check("is_uploaded_file verified", "is_uploaded_file(" in body)
    check("move_uploaded_file used", "move_uploaded_file(" in body)
    check("restrictive permissions applied", "chmod(" in body and "0640" in body)
    check("PDF signature checked", "%PDF-" in body)
    check(
        "only approved MIME types",
        all(
            mime in body
            for mime in ("application/pdf", "image/jpeg", "image/png")
        )
        and "image/gif" not in body
        and "image/webp" not in body,
    )

submit_php = API / "submit-quote.php"
if submit_php.is_file():
    body = read(submit_php)
    check("POST enforced", "'POST'" in body)
    check("HTTPS enforced", "isHttps()" in body)
    check("same-site enforced", "isSameSite()" in body)
    check("multipart enforced", "multipart/form-data" in body)
    check("atomic rate limit consumed", "RateLimiter::consume" in body)
    check("CSRF verified", "verifyCsrfToken" in body)
    check("submission token verified", "verifySubmissionToken" in body)
    check("idempotency replay handled", "findBySubmissionToken" in body)
    check(
        "duplicate fingerprint lock used",
        "acquireFingerprintLock" in body and "releaseFingerprintLock" in body,
    )
    check("duplicate window handled", "findRecentDuplicate" in body)
    check("transaction opened", "Database::begin()" in body)
    check("transaction committed", "Database::commit()" in body)
    check("rollback on failure", "Database::rollBack()" in body)
    check("stored file removed on failure", "UploadService::remove(" in body)

    commit_pos = body.index("Database::commit()")
    email_pos = body.index("EmailService::sendInternalNotification")
    check("emails attempted only after commit", commit_pos < email_pos)

    check(
        "no lead deletion anywhere in the handler",
        not re.search(r"DELETE\s+FROM\s+quote_requests", body, re.IGNORECASE),
    )

repo_php = SRC / "QuoteRepository.php"
if repo_php.is_file():
    body = read(repo_php)
    check("initial status is New", "INITIAL_STATUS = 'New'" in body)
    check(
        "repository never deletes quote data",
        not re.search(r"DELETE\s+FROM\s+quote_requests", body, re.IGNORECASE),
    )

ref_php = SRC / "ReferenceGenerator.php"
if ref_php.is_file():
    body = read(ref_php)
    # Comments are stripped throughout: the docblock names both MAX()/COUNT()
    # and the superseded FOR UPDATE approach precisely to record that they are
    # NOT used, so prose must not satisfy these checks.
    ref_code = strip_comments(body)

    check("requires an open transaction", "inTransaction()" in ref_code)
    check(
        "allocation is a single atomic upsert",
        "ON DUPLICATE KEY UPDATE" in ref_code.upper()
        and "LAST_INSERT_ID(" in ref_code.upper(),
    )
    check(
        "no multi-statement lock sequence (deadlock regression guard)",
        "FOR UPDATE" not in ref_code.upper()
        and "INSERT IGNORE" not in ref_code.upper(),
    )
    check(
        "exactly one SQL statement in the allocator",
        len(sql_literals(body)) == 1,
        f"found {len(sql_literals(body))}",
    )
    check("no MAX() sequence", "MAX(" not in ref_code.upper())
    check("no COUNT() sequence", "COUNT(" not in ref_code.upper())

email_php = SRC / "EmailService.php"
if email_php.is_file():
    body = read(email_php)
    check("email output HTML-escaped", "htmlspecialchars(" in body)
    check("SMTP debug disabled", "DEBUG_OFF" in body)
    check("customer address never used as sender", "addReplyTo(" in body)
    check("credentials redacted from logged errors", "[redacted]" in body)
    check(
        "customer acknowledgment includes contact email",
        "For questions, email" in body,
    )


print("\n── Backend/frontend separation ──")

# Phase 2 must contain no frontend logic of its own. privacy.html is a
# legitimate Phase 3 artifact, so its presence is no longer a failure here;
# tests/frontend_static.py owns the frontend assertions.
backend_php = [p for p in php_files()]
check(
    "no HTML form markup inside the backend",
    not any("<form" in read(p) for p in backend_php),
)
check(
    "no backend file references the frontend stylesheet",
    not any("styles/main.css" in read(p) for p in backend_php),
)

components = ROOT / "components"
check(
    "components directory unchanged in count",
    components.is_dir() and len(list(components.glob("*.html"))) == 14,
)


print("\n────────────────────────────────")
print(f"  {len(passes)} passed, {len(failures)} failed\n")

sys.exit(1 if failures else 0)
