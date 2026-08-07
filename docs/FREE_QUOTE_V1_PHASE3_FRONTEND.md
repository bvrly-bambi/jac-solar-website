# Free Quote V1 — Phase 3 Frontend

Connects the existing website form to the Phase 2 PHP backend. No redesign:
the approved layout, typography, and colour system are unchanged.

## ⚠️ WARNING

**Hostinger auto-deploys `main` to the live site (jacsolarcorp.com).**
Phase 3 stays on `feature/free-quote-v1` and is tested on staging first.

Root files are the canonical source. `staging-app/` was **not** modified and
must be updated by manual upload after review.

---

## Files modified and created

| File | Action | Purpose |
|------|--------|---------|
| `index.html` | Modified | Real `<form>`, backend field names, hidden tokens, honeypot, inline validation, fetch submission, status UI |
| `components/12_contact.html` | Modified | Reference copy, synchronized with the contact markup in `index.html` |
| `styles/main.css` | Modified | Appended styles for errors, status states, upload states, focus visibility |
| `privacy.html` | Created | Privacy notice, version `2026-07-30-v1` |
| `docs/FREE_QUOTE_V1_PHASE3_FRONTEND.md` | Created | This document |
| `tests/frontend_static.py` | Created | Static markup/contract checks |
| `tests/frontend_functional.mjs` | Created | jsdom behavioural tests |

**Not modified:** `api/`, `database/`, `config.example.php`, `composer.json`,
`staging-app/`, and every Phase 2 test.

`components/12_contact.html` is reference-only. **`index.html` is the executing
source** — edit it first, then mirror into the component.

---

## Form field contract

```
<form action="api/submit-quote.php" method="post"
      enctype="multipart/form-data" id="quoteForm" novalidate>
```

`novalidate` is deliberate: the script runs its own checks so messages are
consistent across browsers. `required` attributes remain for assistive tech.

### Required

| Name | Control | Client rule |
|------|---------|-------------|
| `full_name` | text | 2–100 characters |
| `contact_number` | text | PH local or `+63` |
| `email` | email | valid format |
| `project_location` | text | 1–255 characters |
| `electricity_provider` | text | 1–150 characters |
| `property_type` | select | allowlist |
| `bill_range` | select | allowlist |
| `electricity_bill` | file | one file, PDF/JPG/JPEG/PNG, ≤10 MB |
| `processing_consent` | checkbox | must be ticked, unticked initially |
| `csrf_token` | hidden | from `api/csrf-token.php` |
| `submission_token` | hidden | from `api/csrf-token.php` |

### Optional

| Name | Control | Client rule |
|------|---------|-------------|
| `message` | textarea | ≤2,000 characters |
| `specific_requirements` | textarea | ≤2,000 characters |
| `marketing_consent` | checkbox | optional, unticked initially |

### Honeypot

`website` — a text input inside `.hp-field`, positioned off-screen (not
`display:none`, so automated fillers still see it), `tabindex="-1"`,
`autocomplete="off"`, never required. It must always submit empty.

### Allowlists

Property types: `Residential`, `Commercial / Industrial`, `Agricultural`,
`School / Institution`, `Government`, `Other`.

Bill ranges: `Below ₱5,000`, `₱5,000–₱8,000`, `₱6,000–₱10,000`,
`₱8,000–₱12,000`, `₱10,000–₱14,000`, `₱14,000–₱18,000`, `₱16,000–₱22,000`,
`₱18,000–₱24,000`, `₱20,000–₱30,000`, `₱30,000 and above`.

`₱30,000 and above` is a customized-assessment category for higher-consumption inquiries and is not a guaranteed package match.

The **en dash** (`–`, U+2013) is significant. The backend folds hyphen, em
dash, and minus sign to en dash before matching, but the markup uses the exact
approved strings. A note under the selects states that ranges guide the initial
assessment and that final system size is confirmed after bill review and a site
visit — they are not guaranteed recommendations.

Upload `accept` is restricted to
`.pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png`. `image/*` is not
used.

---

## Token loading

On page load the script calls:

```js
fetch('api/csrf-token.php', {
  method: 'GET', credentials: 'same-origin', cache: 'no-store'
})
```

- Success → fills `csrf_token` and `submission_token`, enables the submit button.
- Every token refresh immediately clears the old client-side tokens and disables submission until the replacement pair arrives, preventing a brief empty/stale-token submit window.
- Failure → the button stays disabled and a non-sensitive message appears
  telling the visitor to refresh, with `chris@jacsolarcorp.com` as a fallback.

The submit button ships **disabled** in the markup, so it cannot be used before
tokens exist even if JavaScript is slow or blocked.

Tokens are re-fetched after `success`, `already_received`, and `csrf_error`,
because the backend consumes the submission token once a lead is committed.

---

## Submission

```js
fetch('api/submit-quote.php', {
  method: 'POST', credentials: 'same-origin', cache: 'no-store',
  body: new FormData(form)
})
```

`Content-Type` is never set manually — `FormData` supplies the multipart
boundary. `event.preventDefault()` stops any page redirect.

**Double-submit protection:** an in-flight flag plus a disabled button with
`aria-busy="true"` and a "Sending your request…" label. A second submit while
one is in flight is ignored outright.

---

## Response states

Field names read from the response come from the real Phase 2 contract
(`api/src/Response.php`).

| `status` | UI |
|----------|-----|
| `success` | Green panel: "Thank you! Your Free Quote request has been received.", reference number, acknowledgment confirmation, one-to-two-business-days line. Form resets, tokens refresh. |
| `already_received` | Blue panel explaining the request was already received, with the **original** reference number. No resubmission, no duplicate email. |
| `validation_error` | Red panel plus per-field messages from `errors`, mapped by field name. Entered data preserved. Focus moves to the first bad field. |
| `upload_error` | Same handling, mapped to `electricity_bill`. |
| `csrf_error` | Red panel, tokens refreshed automatically, entered data preserved. |
| `rate_limited` | Red panel; `retry_after_seconds` converted to minutes. Support address shown. |
| `server_error` | Red panel with the backend's generic message. Support address shown. |
| network failure | Explains that receipt could not be confirmed, preserves the form, and recommends one duplicate-safe retry using the same submission token. |

**Email-delay notice:** when `success` carries a `notice`, it is displayed
inside the success panel. The request stays a success — it is never downgraded
to an error.

**Support address rule:** `chris@jacsolarcorp.com` appears only where the
the request is not confirmed as saved (`rate_limited`, `server_error`, network failure, token
failure). It never appears on `success` or `already_received`.

Raw PHP, SQL, SMTP, stack-trace, and server-path text is never rendered. All
server-supplied strings pass through HTML escaping before insertion.

---

## Accessibility

- Every visible control has a `<label for="…">`; error text is linked with
  `aria-describedby`.
- Status region: `role="status"`, `aria-live="polite"`, `tabindex="-1"`. Focus
  moves there after every response.
- Invalid fields get `aria-invalid="true"`; focus moves to the first one.
- Status is never colour-only — each message carries a text heading plus a
  glyph (`✓`, `ⓘ`, `⚠`), and field errors get a `⚠` via CSS `::before`.
- `:focus-visible` outlines on every control, including the upload box.
- Field errors clear as soon as the visitor edits that field.
- Layout and breakpoints unchanged; status panel and upload actions adapt at
  ≤600px.

---

## Upload UI

The existing upload-box design is retained and made functional. On selection it
shows the filename and human-readable size, turns green, and reveals a
**Remove file** button. Invalid type, empty, oversized, or multi-file
selections produce a clear message and the input is cleared.

Client-side checks are convenience only. The backend re-verifies the real MIME
type with `finfo`, structure with `getimagesize`, the PDF signature, and size.
Neither the UI nor `privacy.html` claims client checks prove a file is safe.

---

## Privacy

The former claim — that data is never shared with third parties — has been
removed. The note beside the form now states that details and the uploaded bill
are used to assess the request and prepare a quotation, are processed using JAC
Solar's hosting and email providers, are accessible only to authorized
personnel, and are protected by security safeguards, with a link to
`privacy.html`.

`privacy.html` covers: information collected, purpose, electricity-bill
processing, hosting and email providers, authorized internal access, security
safeguards, retention and archiving, correction and deletion requests, and
contact at `chris@jacsolarcorp.com`. It sets **no fixed retention deadline**,
since none is approved in the decision record, and makes no absolute security
guarantee. Version string: `2026-07-30-v1`, matching
`privacy_notice_version` in the config.

---

## Staging deployment

Root files are canonical. Upload them to the staging document root
(`public_html/staging-app/`):

1. `index.html`
2. `privacy.html`
3. `styles/main.css`
4. `components/12_contact.html` *(reference only; optional to upload)*

Do **not** upload `tests/`.

`api/` and `vendor/` should already be in place from Phase 2. The form calls
`api/submit-quote.php` as a **relative** path, so it resolves correctly under
the staging document root without edits.

After upload:

- Load `https://staging.jacsolarcorp.com/` and open the browser console.
- Confirm `api/csrf-token.php` returns 200 JSON and the submit button enables.
- Confirm `https://staging.jacsolarcorp.com/privacy.html` renders.

---

## Manual test checklist

### Desktop

| # | Test | Expected |
|---|------|----------|
| 1 | Load the page | Submit button enables within a moment; no console errors |
| 2 | Submit empty | Inline errors on every required field; nothing sent |
| 3 | Bad email | Email error only |
| 4 | `09171234567` and `+63 917 123 4567` | Both accepted |
| 5 | Upload a `.docx` | Rejected with a type message |
| 6 | Upload a file >10 MB | Rejected with a size message |
| 7 | Upload a valid PDF | Filename, size, green box, Remove button |
| 8 | Click Remove | Selection cleared, box resets |
| 9 | Submit without consent | Consent error |
| 10 | Valid submission | Success panel with `JACQ-` reference; form clears |
| 11 | Check both mailboxes | Internal mail has the bill attached; customer mail does not |
| 12 | Resubmit identical data within 15 min | `already_received` with the original reference; no second email |
| 13 | Six submissions in an hour | Sixth is rate-limited with a wait time |
| 14 | Keyboard only (Tab/Enter) | All controls reachable, focus always visible |
| 15 | Screen reader | Status announced without moving focus manually |
| 16 | Privacy link | Opens `privacy.html` in a new tab |

### Mobile

| # | Test | Expected |
|---|------|----------|
| 17 | Load on a phone | Layout matches the previous design; no horizontal scroll |
| 18 | Tap the upload box | Camera/file picker opens; photo accepted as JPG/PNG |
| 19 | Submit with errors | Page scrolls to the first bad field |
| 20 | Successful submission | Status panel readable without zooming |
| 21 | Rotate to landscape | Layout holds |
| 22 | Tap targets | Checkboxes and buttons comfortably tappable |

### Verify on the server

```sql
SELECT reference_number, lead_status, email, contact_number,
       property_type, bill_range, submitted_at
  FROM quote_requests ORDER BY id DESC LIMIT 5;
```

Confirm `lead_status` is `New`, `contact_number` is normalized to `+63…`,
`email` is lowercase, and the bill sits in the private directory under a random
name and is **not** reachable over HTTPS.

---

## Rollback

Phase 3 touches only frontend files. No schema change, no backend change.

**Full rollback**

```bash
git revert <phase-3-commit>
```

Then re-upload the reverted `index.html` and `styles/main.css` to staging and
delete `privacy.html`. The Phase 2 backend keeps working; it simply receives no
submissions.

**Partial rollback (staging only, no commit)** — re-upload the previous
`index.html` and `styles/main.css` from `git show HEAD~1:index.html`.

Do **not** drop any tables or delete uploaded bills: staging may already hold
real test leads, and `quote_email_events` has `ON DELETE RESTRICT`.

---

## Staging test cleanup

1. **Remove test uploads** from the private directory:

   ```sql
   SELECT reference_number, stored_filename FROM quote_requests
    WHERE email = '<your test address>';
   ```

   Delete only those `stored_filename` values.

2. **Archive rather than delete test rows** — the foreign key refuses deletion:

   ```sql
   UPDATE quote_requests
      SET lead_status = 'Spam / Invalid', is_archived = 1, archived_at = NOW()
    WHERE email = '<your test address>';
   ```

3. **Clear test rate-limit rows** if you tripped the limit:

   ```sql
   DELETE FROM quote_rate_limits WHERE ip_address = '<your test IP>';
   ```

4. **Do not reset `quote_reference_counters`.** Lowering `last_sequence` risks
   colliding with an existing reference number.

5. **Clear test emails** from `chris@jacsolarcorp.com` and any customer test
   inbox, and delete local sample bills.

---

## Running the tests

```bash
python3 tests/frontend_static.py      # markup and contract checks
npm install jsdom                     # once
node tests/frontend_functional.mjs    # behavioural checks
```

`frontend_functional.mjs` stubs `fetch`, so it never contacts a server and
needs no credentials. If jsdom lives elsewhere, point `JSDOM_FROM` at a
directory that can resolve it.
