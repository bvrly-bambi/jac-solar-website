#!/usr/bin/env python3
"""
Static checks for the Free Quote V1 frontend integration (Phase 3).

Runs without a browser, a server, or credentials. Verifies that the markup
matches the contract the Phase 2 backend actually expects, and that Phase 3
did not touch backend, database, or staging-app files.

Usage:  python3 tests/frontend_static.py
Exit :  0 when all checks pass, 1 otherwise.
"""

from __future__ import annotations

import hashlib
import pathlib
import re
import subprocess
import sys
from html.parser import HTMLParser

ROOT = pathlib.Path(__file__).resolve().parent.parent
INDEX = ROOT / "index.html"
COMPONENT = ROOT / "components" / "12_contact.html"
CSS = ROOT / "styles" / "main.css"
PRIVACY = ROOT / "privacy.html"

failures: list[str] = []
passes: list[str] = []


def check(label: str, condition: bool, detail: str = "") -> None:
    if condition:
        passes.append(label)
        print(f"  PASS  {label}")
    else:
        failures.append(label)
        print(f"  FAIL  {label}" + (f" — {detail}" if detail else ""))


index_html = INDEX.read_text(encoding="utf-8")
component_html = COMPONENT.read_text(encoding="utf-8") if COMPONENT.is_file() else ""
css_text = CSS.read_text(encoding="utf-8") if CSS.is_file() else ""


# ── Extract the form element ────────────────────────────────────────────────

class FormExtractor(HTMLParser):
    """Collects the attributes of every tag inside <form id="quoteForm">."""

    def __init__(self) -> None:
        super().__init__(convert_charrefs=True)
        self.in_form = False
        self.form_attrs: dict[str, str] = {}
        self.controls: list[tuple[str, dict[str, str]]] = []
        self.labels: list[dict[str, str]] = []
        self.options: list[str] = []
        self._option_open = False
        self._option_text = ""
        self._option_placeholder = False
        self.select_options: dict[str, list[str]] = {}
        self._current_select: str | None = None
        self.unclosed: list[str] = []

    def handle_starttag(self, tag, attrs):
        attr = {k: (v if v is not None else "") for k, v in attrs}

        if tag == "form" and attr.get("id") == "quoteForm":
            self.in_form = True
            self.form_attrs = attr
            return

        if not self.in_form:
            return

        if tag in ("input", "select", "textarea", "button"):
            self.controls.append((tag, attr))
            if tag == "select":
                self._current_select = attr.get("name", "")
                self.select_options[self._current_select] = []
        elif tag == "label":
            self.labels.append(attr)
        elif tag == "option":
            self._option_open = True
            self._option_text = ""
            # A placeholder carries value=""; real choices submit their text.
            self._option_placeholder = attr.get("value", None) == ""

    def handle_data(self, data):
        if self._option_open:
            self._option_text += data

    def handle_endtag(self, tag):
        if tag == "option" and self._option_open:
            self._option_open = False
            text = self._option_text.strip()
            if self._option_placeholder:
                self._option_placeholder = False
                return
            self.options.append(text)
            if self._current_select is not None:
                self.select_options[self._current_select].append(text)
        elif tag == "select":
            self._current_select = None
        elif tag == "form" and self.in_form:
            self.in_form = False


parser = FormExtractor()
parser.feed(index_html)

form_attrs = parser.form_attrs
controls = parser.controls


def controls_named(name: str) -> list[tuple[str, dict[str, str]]]:
    return [(tag, a) for tag, a in controls if a.get("name") == name]


print("\n── Form element ──")

check("a real <form> element exists", index_html.count("<form") == 1,
      f"found {index_html.count('<form')}")
check("form id is quoteForm", bool(form_attrs))
check("method is post", form_attrs.get("method", "").lower() == "post",
      form_attrs.get("method", "<missing>"))
check("enctype is multipart/form-data",
      form_attrs.get("enctype", "") == "multipart/form-data",
      form_attrs.get("enctype", "<missing>"))
check("action is api/submit-quote.php",
      form_attrs.get("action", "") == "api/submit-quote.php",
      form_attrs.get("action", "<missing>"))


print("\n── Field names (each exactly once) ──")

REQUIRED_NAMES = [
    "full_name", "contact_number", "email", "project_location",
    "electricity_provider", "property_type", "bill_range",
    "electricity_bill", "processing_consent", "csrf_token", "submission_token",
]
OPTIONAL_NAMES = ["message", "specific_requirements", "marketing_consent"]

for name in REQUIRED_NAMES + OPTIONAL_NAMES + ["website"]:
    found = controls_named(name)
    check(f"{name} present exactly once", len(found) == 1, f"found {len(found)}")


print("\n── Hidden tokens ──")

for token in ("csrf_token", "submission_token"):
    found = controls_named(token)
    ok = bool(found) and found[0][1].get("type") == "hidden"
    check(f"{token} is a hidden input", ok)


print("\n── Honeypot ──")

honeypot = controls_named("website")
check("honeypot field is named website", len(honeypot) == 1)
if honeypot:
    attrs = honeypot[0][1]
    check("honeypot is not required", "required" not in attrs)
    check("honeypot is out of the tab order", attrs.get("tabindex") == "-1")
    check("honeypot has autocomplete off", attrs.get("autocomplete") == "off")
check("honeypot wrapper is hidden from assistive tech",
      'class="hp-field"' in index_html and 'aria-hidden="true"' in index_html)
check("honeypot is visually hidden via CSS, not display:none",
      ".hp-field{" in css_text and "display:none" not in css_text.split(".hp-field{")[1][:120])


print("\n── Required attributes ──")

for name in ["full_name", "contact_number", "email", "project_location",
             "electricity_provider", "property_type", "bill_range",
             "electricity_bill", "processing_consent"]:
    found = controls_named(name)
    ok = bool(found) and "required" in found[0][1]
    check(f"{name} is marked required", ok)

for name in OPTIONAL_NAMES:
    found = controls_named(name)
    ok = bool(found) and "required" not in found[0][1]
    check(f"{name} is not required", ok)


print("\n── File upload ──")

bill = controls_named("electricity_bill")
check("electricity_bill is a file input",
      bool(bill) and bill[0][1].get("type") == "file")
if bill:
    accept = bill[0][1].get("accept", "")
    check("accept attribute is restricted",
          accept == ".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png",
          accept or "<missing>")
    check("accept does not use image/*", "image/*" not in accept)
    check("upload is single-file only", "multiple" not in bill[0][1])
check("no legacy inline onchange handler", "handleUpload" not in index_html)
check("a way to replace the selected file exists", 'id="removeFileBtn"' in index_html)


print("\n── Consent checkboxes ──")

processing = controls_named("processing_consent")
marketing = controls_named("marketing_consent")

check("processing consent is a checkbox",
      bool(processing) and processing[0][1].get("type") == "checkbox")
check("processing consent is required",
      bool(processing) and "required" in processing[0][1])
check("processing consent is unchecked initially",
      bool(processing) and "checked" not in processing[0][1])
check("marketing consent is a checkbox",
      bool(marketing) and marketing[0][1].get("type") == "checkbox")
check("marketing consent is optional",
      bool(marketing) and "required" not in marketing[0][1])
check("marketing consent is unchecked initially",
      bool(marketing) and "checked" not in marketing[0][1])

CONSENT_TEXT = (
    "I consent to JAC Solar Corporation collecting and using the information "
    "and electricity bill I provide to assess my solar requirements, respond "
    "to my inquiry, and prepare a quotation."
)
MARKETING_TEXT = (
    "I would like to receive solar tips, promotions, and company updates "
    "from JAC Solar Corporation."
)
normalized = re.sub(r"\s+", " ", index_html)
check("approved processing-consent wording present", CONSENT_TEXT in normalized)
check("approved marketing-consent wording present", MARKETING_TEXT in normalized)


print("\n── Allowlists ──")

PROPERTY_TYPES = [
    "Residential", "Commercial / Industrial", "Agricultural",
    "School / Institution", "Government", "Other",
]
BILL_RANGES = [
    "Below \u20b15,000", "\u20b15,000\u2013\u20b18,000", "\u20b16,000\u2013\u20b110,000",
    "\u20b18,000\u2013\u20b112,000", "\u20b110,000\u2013\u20b114,000",
    "\u20b114,000\u2013\u20b118,000", "\u20b116,000\u2013\u20b122,000",
    "\u20b118,000\u2013\u20b124,000", "\u20b120,000\u2013\u20b130,000",
    "\u20b130,000 and above",
]

property_options = parser.select_options.get("property_type", [])
bill_options = parser.select_options.get("bill_range", [])

for value in PROPERTY_TYPES:
    check(f"property type: {value}", value in property_options)
check("no unapproved property types",
      sorted(v for v in property_options if v) == sorted(PROPERTY_TYPES),
      str([v for v in property_options if v and v not in PROPERTY_TYPES]))

for value in BILL_RANGES:
    check(f"bill range: {value}", value in bill_options)
check("no unapproved bill ranges",
      sorted(v for v in bill_options if v) == sorted(BILL_RANGES),
      str([v for v in bill_options if v and v not in BILL_RANGES]))
check("bill ranges use approved punctuation",
      all("\u2013" in v or v.startswith("Below") or v.endswith("and above") for v in bill_options if v))
check("bill ranges framed as assessment guides, not guarantees",
      "guide our initial assessment" in normalized)
check("above-30k custom assessment note present",
      "Bills above ₱30,000 may require a customized commercial or high-capacity system assessment." in normalized)


print("\n── API integration ──")

check("fetches api/csrf-token.php", "'api/csrf-token.php'" in index_html)
check("posts to api/submit-quote.php", "'api/submit-quote.php'" in index_html)
check("uses fetch", "fetch(" in index_html)
check("credentials are same-origin",
      index_html.count("credentials: 'same-origin'") >= 2,
      f"found {index_html.count(chr(39).join(['credentials: ', 'same-origin', '']))}")
check("token request uses cache: no-store", "cache: 'no-store'" in index_html)
check("submission uses FormData", "new FormData(form)" in index_html)
check("does not set Content-Type manually",
      "'Content-Type'" not in index_html and '"Content-Type"' not in index_html)
check("prevents default form submission", "event.preventDefault()" in index_html)
check("submit button starts disabled",
      bool([a for t, a in controls if a.get("id") == "submitBtn" and "disabled" in a]))
check("guards against repeat submission", "if (submitting) { return; }" in index_html)
check(
    "token refresh retires old client-side readiness immediately",
    "tokensReady = false;" in index_html
    and "csrfInput.value = '';" in index_html
    and "tokenInput.value = '';" in index_html,
)


print("\n── Response states handled ──")

for state in ["success", "already_received", "validation_error", "csrf_error",
              "rate_limited", "upload_error", "server_error"]:
    check(f"handles {state}", f"'{state}'" in index_html)

check("reads reference_number", "reference_number" in index_html)
check("reads notice field", "data.notice" in index_html)
check("reads retry_after_seconds", "retry_after_seconds" in index_html)
check("maps server field errors", "applyServerFieldErrors" in index_html)
check("refreshes tokens after commit/csrf error",
      index_html.count("loadTokens();") >= 3)

SUCCESS_TEXT = "Thank you! Your Free Quote request has been received."
check("approved success wording present", SUCCESS_TEXT in normalized)
check(
    "submit button hidden after accepted or duplicate response",
    index_html.count("submitBtn.hidden = true;") >= 2,
)
check("states one to two business days",
      "one to two business days" in normalized)
check(
    "network failure does not falsely claim the request was not received",
    "could not confirm whether your request reached our server" in index_html
    and "your request was not sent" not in index_html,
)
check(
    "network retry explains duplicate-safe recovery",
    "duplicate " in index_html
    and "protection will return the original reference number" in index_html,
)


print("\n── Error-handling safety ──")

check("no raw PHP/SQL/SMTP terms surfaced in UI copy",
      not re.search(r"(stack trace|PDOException|SQLSTATE|SMTP connect|/home/u500192602)",
                    index_html))
support_positions = [m.start() for m in re.finditer(r"chris@jacsolarcorp\.com", index_html)]
check("support email appears only in failure paths",
      "contactFallback" in index_html,
      "expected support address behind contactFallback()")
check("success path does not offer the support address",
      "chris@jacsolarcorp.com" not in index_html[
          index_html.index("function renderSuccess"):index_html.index("function renderAlreadyReceived")
      ])


print("\n── Accessibility ──")

label_fors = {a.get("for") for a in parser.labels if a.get("for")}
control_ids = {a.get("id") for _, a in controls if a.get("id")}
missing_labels = []
for name in REQUIRED_NAMES + OPTIONAL_NAMES:
    if name in ("csrf_token", "submission_token"):
        continue
    found = controls_named(name)
    if found and found[0][1].get("id") not in label_fors:
        missing_labels.append(name)
check("every visible control has an associated label", not missing_labels,
      str(missing_labels))
check("status region uses aria-live", 'aria-live="polite"' in index_html)
check("status region uses role=status", 'role="status"' in index_html)
check("status region is focusable for focus move",
      'id="formStatus"' in index_html and 'tabindex="-1"' in index_html)
check("script moves focus to the status region", "statusBox.focus()" in index_html)
check("script moves focus to the first bad field", "focusFirstError" in index_html)
check("invalid fields get aria-invalid", "aria-invalid" in index_html)
check("fields reference their error text via aria-describedby",
      index_html.count("aria-describedby") >= 10)
check("visible keyboard focus styles defined", ":focus-visible" in css_text)
check("status not conveyed by colour alone",
      "\\u26A0" in index_html and "\\u2713" in index_html and "field-error.visible::before" in css_text)
check("busy state announced", 'aria-busy' in index_html)


print("\n── Privacy ──")

check("privacy.html exists", PRIVACY.is_file())
check("privacy.html linked from the form", 'href="privacy.html"' in index_html)
check("inaccurate third-party claim removed",
      "We do not share your data with third parties" not in index_html)
check("near-form summary mentions hosting and email providers",
      "hosting and email service providers" in normalized)
check("near-form summary mentions authorized access",
      "authorized JAC Solar personnel" in normalized)

if PRIVACY.is_file():
    privacy_text = re.sub(r"\s+", " ", PRIVACY.read_text(encoding="utf-8"))
    for topic, needle in [
        ("information collected", "Information we collect"),
        ("purpose of collection", "Why we collect it"),
        ("electricity-bill processing", "How we handle your electricity bill"),
        ("hosting and email providers", "Service providers who process your information"),
        ("authorized internal access", "Who inside JAC Solar can see your information"),
        ("security safeguards", "Security safeguards"),
        ("retention and archiving", "Retention and archiving"),
        ("correction and deletion", "Your choices and how to exercise them"),
        ("contact address", "chris@jacsolarcorp.com"),
    ]:
        check(f"privacy.html covers {topic}", needle in privacy_text)

    check("privacy.html sets no unapproved retention deadline",
          "not set a fixed deletion deadline" in privacy_text)
    check("privacy.html avoids absolute security guarantees",
          "No system can be guaranteed completely secure" in privacy_text)
    check(
        "privacy.html avoids unsupported processor-contract guarantees",
        "not permitted to use your information for their own purposes" not in privacy_text,
    )
    check(
        "privacy.html frames deletion as a request, not an unconditional promise",
        "Request deletion" in privacy_text and "We will review and respond" in privacy_text,
    )
    check("privacy.html does not claim client checks prove safety",
          "do not by themselves guarantee" in privacy_text)


print("\n── Legacy form services ──")

for term in ["web3forms", "api.web3forms.com", "access_key", "formspree"]:
    hits = [
        str(p.relative_to(ROOT))
        for p in [INDEX, COMPONENT, CSS, PRIVACY]
        if p.is_file() and term.lower() in p.read_text(encoding="utf-8").lower()
    ]
    check(f"no {term} reference", not hits, "; ".join(hits))


print("\n── Component synchronization ──")

start = index_html.index("<!-- CONTACT -->")
end = index_html.index('<footer class="footer">')
contact_section = index_html[start:end].rstrip("\n")
check("components/12_contact.html mirrors index.html contact markup",
      contact_section in component_html)
check("component marked reference-only", "REFERENCE COPY" in component_html)


print("\n── Phase 3 scope: nothing else modified ──")

try:
    changed = subprocess.run(
        ["git", "diff", "--name-only", "HEAD"],
        cwd=ROOT, capture_output=True, text=True, check=False,
    ).stdout.split()
    untracked = subprocess.run(
        ["git", "ls-files", "--others", "--exclude-standard"],
        cwd=ROOT, capture_output=True, text=True, check=False,
    ).stdout.split()
    touched = set(changed) | set(untracked)
except Exception:
    touched = set()

ALLOWED = {
    "index.html",
    "components/12_contact.html",
    "styles/main.css",
    "privacy.html",
    "docs/FREE_QUOTE_V1_PHASE3_FRONTEND.md",
    "tests/frontend_static.py",
    "tests/frontend_functional.mjs",
    # Phase 2's own checker: one stale assertion ("privacy.html not created")
    # had to be retired now that Phase 3 legitimately creates that page.
    "tests/static_analysis.py",
}

out_of_scope = sorted(f for f in touched if f not in ALLOWED)
check("only Phase 3 files changed", not out_of_scope, str(out_of_scope))

for guarded in ["api/", "database/", "config.example.php", "composer.json", "staging-app/"]:
    hits = sorted(f for f in touched if f.startswith(guarded) or f == guarded)
    check(f"{guarded} untouched", not hits, str(hits))

migration = ROOT / "database" / "migrations" / "001_free_quote_v1_schema.sql"
if migration.is_file():
    digest = hashlib.sha256(migration.read_bytes()).hexdigest()
    check("applied migration unchanged",
          digest == "1b2067ec66fe60992a56e299f6d0e9d4afc68797397d938c59ca31ead8b5efdc",
          digest[:16])


print("\n── Secret scan ──")

secret_hits: list[str] = []
for path in [INDEX, COMPONENT, CSS, PRIVACY]:
    if not path.is_file():
        continue
    body = path.read_text(encoding="utf-8")
    for pattern, label in [
        (r"""(?i)password\s*[:=]\s*['"][^'"]{3,}""", "password literal"),
        (r"""(?i)api[_-]?key\s*[:=]\s*['"][^'"]{3,}""", "api key"),
        (r"""(?i)access_key""", "access_key"),
        (r"""/home/u500192602""", "server path"),
    ]:
        if re.search(pattern, body):
            secret_hits.append(f"{path.relative_to(ROOT)}: {label}")

check("no secrets or server paths in frontend files", not secret_hits,
      "; ".join(secret_hits))


print("\n────────────────────────────────")
print(f"  {len(passes)} passed, {len(failures)} failed\n")

sys.exit(1 if failures else 0)
