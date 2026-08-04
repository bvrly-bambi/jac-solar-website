# Free Quote V1 — Phase 2 Backend

PHP submission backend for the Free Quote form. Frontend files are untouched
in this phase.

## ⚠️ WARNING

**Hostinger auto-deploys `main` to the live site (jacsolarcorp.com).**
All Phase 2 work stays on `feature/free-quote-v1` and is tested on staging
first. Never push directly to `main`.

---

## Files created

### Public endpoints

| File | Purpose |
|------|---------|
| `api/csrf-token.php` | Issues a CSRF token and a fresh submission/idempotency token. GET only. |
| `api/submit-quote.php` | Handles the form submission. POST multipart/form-data only. |
| `api/bootstrap.php` | Shared init: autoloader, config, timezone, error suppression. Not directly callable. |
| `api/.htaccess` | Denies direct access to internals, disables indexes and error display. |

### Internal modules (`api/src/`, PSR-4 namespace `JacSolar\`)

| File | Responsibility |
|------|----------------|
| `Config.php` | Loads the external configuration; validates required keys. |
| `Response.php` | JSON envelopes, no-cache headers, internal-only logging. |
| `Security.php` | Hardened sessions, CSRF, HTTPS and same-site checks, client IP. |
| `Database.php` | Single PDO connection, exceptions on, emulated prepares off. |
| `Validator.php` | Field validation, normalization, allowlists, honeypot. |
| `RateLimiter.php` | 5 attempts per IP per rolling hour. |
| `UploadService.php` | Upload validation, hashing, private storage, cleanup. |
| `DuplicateDetector.php` | Idempotency replay and 15-minute duplicate window. |
| `ReferenceGenerator.php` | Atomic `JACQ-YYYYMMDD-#####` allocation. |
| `QuoteRepository.php` | Inserts leads; logs email events. |
| `EmailService.php` | Internal notification and customer acknowledgment via PHPMailer. |

### Tests (local only — never upload to Hostinger)

| File | Needs |
|------|-------|
| `tests/ValidatorTest.php` | Nothing. Pure logic. |
| `tests/static_analysis.py` | Python 3. No PHP, no database. |
| `tests/integration_local.php` | A throwaway MySQL database. |
| `tests/concurrency_local.php` | A throwaway MySQL database + `pcntl`. |

---

## Request contract

### `GET /api/csrf-token.php`

No parameters. Returns:

```json
{
  "status": "success",
  "csrf_token": "<64 hex chars>",
  "submission_token": "<64 hex chars>"
}
```

Call this on page load and again after any successful submission, because the
submission token is consumed once a lead is committed.

### `POST /api/submit-quote.php`

`Content-Type: multipart/form-data`. Same-origin only.

**Required fields**

| Field | Rule |
|-------|------|
| `full_name` | 2–100 characters |
| `contact_number` | PH local or `+63`; normalized to `+63XXXXXXXXXX` |
| `email` | Valid address; normalized to lowercase |
| `project_location` | 1–255 characters |
| `electricity_provider` | 1–150 characters, free text |
| `property_type` | Allowlist (below) |
| `bill_range` | Allowlist (below) |
| `electricity_bill` | One file: PDF, JPEG or PNG, max 10 MB |
| `processing_consent` | Must be truthy (`1`, `true`, `on`, `yes`) |
| `csrf_token` | From `csrf-token.php` |
| `submission_token` | From `csrf-token.php` |

**Optional fields**

| Field | Rule |
|-------|------|
| `message` | Max 2,000 characters |
| `specific_requirements` | Max 2,000 characters |
| `marketing_consent` | Truthy or absent; defaults to false |
| `website` | **Honeypot.** Must stay empty and hidden. |

**Property type allowlist**

```
Residential
Commercial / Industrial
Agricultural
School / Institution
Government
Other
```

**Bill range allowlist**

```
Below ₱5,000
₱5,000–₱8,000
₱6,000–₱10,000
₱8,000–₱12,000
₱10,000–₱14,000
₱14,000–₱18,000
₱16,000–₱22,000
₱18,000–₱24,000
₱20,000–₱30,000
```

The peso sign and **en dash** (`–`, U+2013) are significant. The server folds
hyphen, em dash and minus sign to en dash and collapses whitespace before
matching, so a plain hyphen from the frontend is accepted — but the value must
otherwise match exactly. Anything outside the list is rejected.

---

## Expected JSON responses

Every response is JSON with a `status` field. No stack traces, SQL, SMTP
transcripts, credentials or server paths are ever returned.

| `status` | HTTP | Meaning |
|----------|------|---------|
| `success` | 200 | Lead saved. `reference_number` is authoritative. |
| `already_received` | 200 | Replayed token or exact duplicate. Original reference returned. No new lead, no new email. |
| `validation_error` | 422 | Field errors in `errors`, keyed by field name. |
| `upload_error` | 422 | Problem with `electricity_bill`. |
| `csrf_error` | 403 | Bad/expired CSRF token, bad submission token, or cross-origin. Tell the user to refresh. |
| `rate_limited` | 429 | Over 5 attempts/hour. Includes `retry_after_seconds`. |
| `server_error` | 500 | Generic failure. Details go to the error log only. |

Success:

```json
{
  "status": "success",
  "reference_number": "JACQ-20260730-00001",
  "message": "Your quote request has been received.",
  "notice": null
}
```

**Committed lead with email failure** — still `success`, with `notice` set:

```json
{
  "status": "success",
  "reference_number": "JACQ-20260730-00001",
  "message": "Your quote request has been received.",
  "notice": "Your request is recorded and your reference number is confirmed. The email confirmation may be delayed."
}
```

Once the database commit succeeds the request is received. Email failure never
deletes the lead and never reports non-receipt.

---

## Processing order

1. Method, HTTPS, same-site origin, content type, body size
2. Rate limit check, then record the attempt
3. Session, CSRF, submission token
4. Field validation and normalization
5. Upload validation and SHA-256
6. Idempotency replay, then exact-duplicate window
7. Move bill into private storage under a random name
8. **Begin transaction** → allocate reference → insert lead (`New`) → **commit**
9. Attempt internal email, then customer email
10. Log each attempt in `quote_email_events`
11. Return the reference number

If the move succeeds but the insert fails, the transaction rolls back and the
stored file is deleted.

---

## Composer / PHPMailer

PHPMailer is loaded through Composer autoloading. `vendor/` is gitignored.

```bash
composer install --no-dev --optimize-autoloader
```

Run this locally and upload `vendor/` via File Manager, or run it on the server
if CLI access is available. The endpoints return a generic `server_error` and
log a message if `vendor/autoload.php` is missing.

**`composer.lock` is not committed** — see Limitations.

---

## Staging installation

1. Confirm the external config exists at
   `/home/u500192602/domains/jacsolarcorp.com/jac_quote_config.php`
   with `'environment' => 'staging'`.
2. Confirm the private upload directory exists and is writable:
   `/home/u500192602/domains/jacsolarcorp.com/jac_quote_private_uploads`
3. Upload `api/` (including `.htaccess`) into the staging document root:
   `/home/u500192602/domains/jacsolarcorp.com/public_html/public_html/staging-app/api/`
4. Upload `vendor/` to `/home/u500192602/domains/jacsolarcorp.com/public_html/public_html/staging-app/vendor/`
5. **Do not upload `tests/`.**
6. Verify `https://staging.jacsolarcorp.com/api/csrf-token.php` returns JSON.
7. Verify `https://staging.jacsolarcorp.com/api/src/Config.php` returns 403/404.

The Phase 1 migration has already been applied. Phase 2 requires no schema
change and adds no migration.

---

## Manual API test procedure

Run against **staging only**. Use a cookie jar so the session persists.

**1. Get tokens**

```bash
curl -s -c /tmp/jar.txt https://staging.jacsolarcorp.com/api/csrf-token.php
```

**2. Submit a valid request**

```bash
curl -s -b /tmp/jar.txt \
  -H "Origin: https://staging.jacsolarcorp.com" \
  -F "full_name=Test Person" \
  -F "contact_number=0917 123 4567" \
  -F "email=you@example.com" \
  -F "project_location=Davao City" \
  -F "electricity_provider=Davao Light" \
  -F "property_type=Residential" \
  -F "bill_range=₱8,000–₱12,000" \
  -F "processing_consent=1" \
  -F "csrf_token=<from step 1>" \
  -F "submission_token=<from step 1>" \
  -F "electricity_bill=@/path/to/sample-bill.pdf" \
  https://staging.jacsolarcorp.com/api/submit-quote.php
```

Expect `success` and a `JACQ-` reference.

**3. Checks to run**

| Test | How | Expect |
|------|-----|--------|
| Idempotency | Repeat step 2 with the same tokens | `already_received`, same reference |
| Exact duplicate | New tokens, same email + number + file, within 15 min | `already_received`, original reference |
| Validation | Omit `full_name` | `validation_error` |
| Consent | `processing_consent=0` | `validation_error` |
| Allowlist | `property_type=Warehouse` | `validation_error` |
| Honeypot | Add `website=bot` | `validation_error` |
| Wrong file type | Upload a `.docx` | `upload_error` |
| Disguised file | Rename a `.zip` to `.pdf` | `upload_error` |
| Oversize | Upload >10 MB | `upload_error` |
| Missing bill | Omit the file | `upload_error` |
| CSRF | Send a wrong token | `csrf_error` |
| Method | `curl -X GET` | 405 |
| Rate limit | 6 submissions in an hour | `rate_limited` on the 6th |

**4. Verify server state**

```sql
SELECT reference_number, lead_status, email, contact_number,
       property_type, bill_range, submitted_at
  FROM quote_requests ORDER BY id DESC LIMIT 5;

SELECT quote_request_id, email_type, is_success, error_summary
  FROM quote_email_events ORDER BY id DESC LIMIT 10;
```

Confirm:
- `lead_status` is `New`
- `contact_number` is normalized to `+63…`
- `email` is lowercase
- Both emails arrived; the internal one has the bill attached, the customer one does not
- The stored bill exists in the private directory with a random name and is **not** reachable over HTTPS

---

## Failure and rollback

Phase 2 adds **no schema change**, so rollback is file-level only.

**Roll back the backend**

```bash
rm -rf /home/u500192602/domains/jacsolarcorp.com/public_html/public_html/staging-app/api/
```

The frontend is untouched, so removing `api/` restores the previous state
exactly. Do not drop any tables: the Phase 1 schema is shared with future
phases and may already hold real staging leads.

**If emails fail but leads save** — expected and safe. Inspect
`quote_email_events` for `is_success = 0` and the `error_summary`. Fix the SMTP
config; the leads are intact and can be actioned from the table.

**If the reference sequence looks wrong** — inspect
`quote_reference_counters`. Never edit `last_sequence` downward; that risks
colliding with an existing reference number.

**If uploads fail** — check that the private directory exists, is writable by
PHP, and sits outside `public_html`.

---

## Temporary test-file cleanup

After staging tests:

1. **Remove test uploads** from the private directory. Match them to test rows:

   ```sql
   SELECT reference_number, stored_filename FROM quote_requests
    WHERE email = 'you@example.com';
   ```

   Delete only those `stored_filename` values.

2. **Do not delete test rows from `quote_requests`.** The foreign key from
   `quote_email_events` uses `ON DELETE RESTRICT` and will refuse. Mark them
   instead:

   ```sql
   UPDATE quote_requests
      SET lead_status = 'Spam / Invalid', is_archived = 1, archived_at = NOW()
    WHERE email = 'you@example.com';
   ```

3. **Clear test rate-limit rows** if you tripped the limit:

   ```sql
   DELETE FROM quote_rate_limits WHERE ip_address = '<your test IP>';
   ```

4. **Remove local artifacts**: `/tmp/jar.txt`, sample bills, and any local
   `config.php`. Never upload `tests/` to the server.

---

## Notes and deliberate decisions

- **Client IP uses `REMOTE_ADDR` only.** `X-Forwarded-For` is ignored for rate
  limiting because no verified trusted-proxy allowlist exists; trusting it would
  let a caller bypass the limit by forging a header. `X-Forwarded-Proto` *is*
  consulted, but only for the HTTPS check, where forging it grants nothing.
- **Reference allocation is a single atomic upsert.** An earlier
  three-statement version (`INSERT IGNORE` → `SELECT … FOR UPDATE` → `UPDATE`)
  deadlocked under concurrency. `tests/concurrency_local.php` guards the
  regression.
- **Rate-limit check-and-record is serialized per IP** with a short MySQL advisory
  lock so concurrent requests cannot exceed the fifth-attempt limit.
- **`quote_rate_limits` rows older than 24 hours are pruned** on roughly 1 in 50
  requests. This is the only `DELETE` in the backend and touches no quote data.
- **The submission token is cleared from the session after commit.** A replay of
  the completed POST is resolved from MySQL and returns `already_received` with
  the original reference; the frontend must fetch fresh tokens for a new request.
- **Sessions require HTTPS** (`Secure` cookie). Endpoints will not work over
  plain HTTP, which is intentional.
