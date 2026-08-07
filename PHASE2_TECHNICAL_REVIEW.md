# JAC Solar Free Quote V1 — Phase 2 Technical Review

**Review date:** August 4, 2026  
**Basis:** Claude Phase 2 delivery (`294e751`) plus direct source review.

## Review verdict

**Approved after corrections in this reviewed package.**

The original Phase 2 package had strong validation and a sound modular structure, but four issues needed correction before uploading it to GitHub or staging:

1. **Completed POST replay returned `csrf_error`, not `already_received`.**  
   The handler cleared the submission token from the session after commit and then required the same token to remain in the session before checking MySQL. A browser refresh therefore could not return the original reference as required. The reviewed handler now verifies CSRF, then resolves a well-formed completed token from `quote_requests` when the session copy is no longer present.

2. **Concurrent exact duplicates could both be accepted.**  
   The original check happened before insert without serialization. Two requests with different submission tokens but the same email, contact number, and file hash could pass simultaneously. The reviewed code uses a short MySQL advisory lock keyed by the duplicate fingerprint and holds it through duplicate checking and commit.

3. **Rate-limit check and record were not atomic.**  
   Concurrent requests could all pass the count before any recorded the next attempt. The reviewed `RateLimiter::consume()` serializes check-and-record per IP with a short MySQL advisory lock.

4. **Customer acknowledgment omitted the approved contact email for questions.**  
   Both HTML and plain-text acknowledgments now identify the configured internal recipient email.

Additional corrections:

- `api/.htaccess` now denies every PHP file in `api/` except `csrf-token.php` and `submit-quote.php`.
- Removed `php_flag` directives that may cause HTTP 500 on PHP-FPM/shared-hosting configurations; runtime error display is already disabled in `bootstrap.php`.
- Corrected documented Hostinger paths to the verified private upload directory and actual staging document root.
- Included the missing root `.gitignore` in this delivery.
- Updated static and integration tests to cover the revised rate-limit API and security checks.

## Validation performed on the reviewed package

- PHP syntax check: all PHP files passed on PHP 8.4.16.
- Python syntax check: `tests/static_analysis.py` passed compilation.
- Static safety checks: 63 security and implementation checks passed. The standalone review directory lacked the original repository's `components/` folder, so its unrelated frontend-count assertion could not run in that isolated package; it will run after applying the package to the real branch.
- Secret scan: no real database or SMTP credentials are included.
- The Phase 1 migration remains unchanged; no `002_` migration is required.

## Remaining staging-only verification

These require the real Hostinger environment:

- Composer installation and PHPMailer autoloading.
- `.htaccess` behavior on Hostinger/LiteSpeed.
- MySQL advisory-lock support through the configured application user.
- Real `REMOTE_ADDR` behavior behind Hostinger CDN/proxy before relying on per-client IP rate limiting.
- End-to-end multipart submission, database save, private file storage, and both emails.

Do not deploy to `main` or the live site before these staging checks pass.
