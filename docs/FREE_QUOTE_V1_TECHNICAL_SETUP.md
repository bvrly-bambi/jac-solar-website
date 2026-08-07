# Free Quote V1 — Technical Setup Guide

## ⚠️ WARNING
**Hostinger auto-deploys from `main` to the live site (jacsolarcorp.com).**
Never push directly to `main`. All work happens on `feature/free-quote-v1`,
tested on staging first, and merged only after full validation.

---

## Server Environment

| Component | Detail |
|-----------|--------|
| PHP | 8.3 (Hostinger managed) |
| MySQL | Hostinger managed |
| Database name | `u500192602_jac_quotes` |
| Database user | `u500192602_quotes_app` |
| Database password | *(set on server only — never in source control)* |
| SMTP server | `smtp.hostinger.com` (port 465, SSL) |
| Sender account | `quotes@jacsolarcorp.com` |
| Internal recipient | `chris@jacsolarcorp.com` |

## Directory Structure on Hostinger

```
/home/u500192602/domains/jacsolarcorp.com/
├── jac_quote_config.php          ← EXTERNAL config (outside public_html)
├── jac_quote_private_uploads/   ← Uploaded bills stored here (not web-accessible)
└── public_html/
    ├── index.html                ← Live site (auto-deployed from main)
    ├── public_html/
    │   └── staging-app/          ← Staging subdomain document root
    │       ├── index.html
    │       ├── api/              ← PHP backend (Phase 2)
    │       └── ...
    └── ...
```

## External Configuration

The real config file lives OUTSIDE public_html at:

```
/home/u500192602/domains/jacsolarcorp.com/jac_quote_config.php
```

To create it:
1. Copy `config.example.php` from the repository
2. Upload to the path above via Hostinger File Manager
3. Fill in real database password, SMTP password, and environment values
4. Verify file permissions (readable by PHP, not world-readable)

**This file is excluded from Git via `.gitignore` and must never be committed.**

## Composer / PHPMailer

PHPMailer is loaded via Composer autoloading.

```bash
# On a machine with PHP 8.1+ and Composer installed:
cd /path/to/project
composer install --no-dev --optimize-autoloader
```

Then upload the resulting `vendor/` directory to the server alongside the PHP files.

`vendor/` is excluded from Git via `.gitignore` — it must be uploaded to the server manually
or generated on the server if Composer is available there.

If Hostinger does not have Composer CLI access, run `composer install` locally and
upload the `vendor/` folder via File Manager.

## Database Migration

### Execute (Phase 1 schema)

Via Hostinger phpMyAdmin or SSH:

```bash
mysql -u u500192602_quotes_app -p u500192602_jac_quotes < database/migrations/001_free_quote_v1_schema.sql
```

Or paste the SQL contents directly into phpMyAdmin's SQL tab.

### Rollback (Phase 1 schema)

```sql
-- WARNING: This drops ALL V1 tables and their data irreversibly.
DROP TABLE IF EXISTS quote_rate_limits;
DROP TABLE IF EXISTS quote_email_events;
DROP TABLE IF EXISTS quote_reference_counters;
DROP TABLE IF EXISTS quote_requests;
```

Drop order matters — `quote_email_events` has a foreign key to `quote_requests`,
so it must be dropped first (or use the order shown above).

## Private Upload Directory

Create on the server:

```bash
mkdir -p /home/u500192602/domains/jacsolarcorp.com/jac_quote_private_uploads
chmod 750 /home/u500192602/domains/jacsolarcorp.com/jac_quote_private_uploads
```

Verify that this path is NOT inside `public_html` and is not web-accessible.

## Staging

- Staging subdomain: `staging.jacsolarcorp.com`
- Document root: `/home/u500192602/domains/jacsolarcorp.com/public_html/public_html/staging-app/`
- All new features deploy to staging first
- The staging config should set `'environment' => 'staging'`

## Deployment Workflow

1. Commit to `feature/free-quote-v1`
2. Upload changed files to `staging-app/` via Hostinger File Manager
3. Test on `staging.jacsolarcorp.com`
4. When validated, merge `feature/free-quote-v1` → `main`
5. Hostinger auto-deploys `main` to live `public_html/`
6. Verify on `jacsolarcorp.com`
