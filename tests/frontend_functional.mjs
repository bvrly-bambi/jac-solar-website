/**
 * Functional test for the Free Quote V1 frontend integration.
 *
 * Loads index.html in jsdom, stubs fetch, and drives the real inline script
 * through token loading, client-side validation, and every response state the
 * Phase 2 backend can return. No server, no credentials, no network.
 *
 * Usage:  node tests/frontend_functional.mjs
 * Exit :  0 when all assertions pass, 1 otherwise.
 *
 * Requires jsdom. Install locally with:  npm install jsdom
 * Never upload this file to Hostinger.
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createRequire } from 'node:module';

const here = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(here, '..');

const require = createRequire(process.env.JSDOM_FROM || import.meta.url);
let JSDOM;
try {
  ({ JSDOM } = require('jsdom'));
} catch {
  console.error('jsdom is not installed. Run: npm install jsdom');
  console.error('Or point JSDOM_FROM at a directory that has it.');
  process.exit(1);
}

let passed = 0;
let failed = 0;

function check(label, condition, detail = '') {
  if (condition) {
    passed++;
    console.log(`  PASS  ${label}`);
  } else {
    failed++;
    console.log(`  FAIL  ${label}${detail ? ` — ${detail}` : ''}`);
  }
}

const html = fs.readFileSync(path.join(root, 'index.html'), 'utf8');

check(
  'token loader retires previous readiness before fetching',
  html.includes("tokensReady = false;") &&
  html.includes("csrfInput.value = '';") &&
  html.includes("tokenInput.value = '';")
);

/** Build a fresh page whose fetch is scripted by the caller. */
async function makePage(handlers = {}) {
  const calls = [];

  const dom = new JSDOM(html, {
    url: 'https://staging.jacsolarcorp.com/',
    runScripts: 'dangerously',
    pretendToBeVisual: true,
    beforeParse(window) {
      window.fetch = (url, options = {}) => {
        calls.push({ url, options });

        if (url.includes('csrf-token.php')) {
          const handler = handlers.token;
          if (handler === 'network-error') {
            return Promise.reject(new Error('offline'));
          }
          const body = handler || {
            status: 'success',
            csrf_token: 'a'.repeat(64),
            submission_token: 'b'.repeat(64),
          };
          return Promise.resolve({ ok: true, json: () => Promise.resolve(body) });
        }

        if (url.includes('submit-quote.php')) {
          const handler = handlers.submit;
          if (handler === 'network-error') {
            return Promise.reject(new Error('offline'));
          }
          return Promise.resolve({ ok: true, json: () => Promise.resolve(handler) });
        }

        return Promise.reject(new Error('unexpected url ' + url));
      };

      // jsdom implements neither of these. IntersectionObserver is used by the
      // site's pre-existing scroll-animation script, not by the quote form.
      window.HTMLElement.prototype.scrollIntoView = function () {};
      window.IntersectionObserver = class {
        observe() {}
        unobserve() {}
        disconnect() {}
      };
    },
  });

  const { window } = dom;
  await new Promise((resolve) => {
    if (window.document.readyState === 'complete') { resolve(); }
    else { window.addEventListener('load', resolve); }
  });
  await tick(window, 3);

  return { window, doc: window.document, calls };
}

function tick(window, times = 1) {
  return new Promise((resolve) => {
    let n = 0;
    const step = () => {
      n += 1;
      if (n >= times) { resolve(); } else { window.setTimeout(step, 0); }
    };
    window.setTimeout(step, 0);
  });
}

/** Attach a fake file to the upload input, since jsdom has no file picker. */
function attachFile(window, input, { name = 'bill.pdf', type = 'application/pdf', size = 204800 } = {}) {
  const file = new window.File(['x'], name, { type });
  Object.defineProperty(file, 'size', { value: size });
  Object.defineProperty(input, 'files', {
    configurable: true,
    value: Object.assign([file], { length: 1, item: (i) => [file][i] }),
  });
  input.dispatchEvent(new window.Event('change', { bubbles: true }));
  return file;
}

function fillValid(window, doc, { withFile = true } = {}) {
  const set = (name, value) => {
    const el = doc.querySelector(`[name="${name}"]`);
    el.value = value;
  };
  set('full_name', 'Juan Dela Cruz');
  set('contact_number', '0917 123 4567');
  set('email', 'juan@example.com');
  set('project_location', 'Davao City');
  set('electricity_provider', 'Davao Light');
  set('property_type', 'Residential');
  set('bill_range', '\u20B18,000\u2013\u20B112,000');
  doc.querySelector('[name="processing_consent"]').checked = true;
  if (withFile) {
    attachFile(window, doc.getElementById('billUpload'));
  }
}

async function submit(window, doc, waits = 6) {
  doc.getElementById('quoteForm').dispatchEvent(
    new window.Event('submit', { bubbles: true, cancelable: true })
  );
  await tick(window, waits);
}

const statusText = (doc) => doc.getElementById('formStatus').textContent;
const fieldError = (doc, name) => doc.getElementById('err-' + name).textContent;

// ── 1. Token loading ────────────────────────────────────────────────────────

console.log('\n\u2500\u2500 Token loading \u2500\u2500');
{
  const { window, doc, calls } = await makePage();

  check('requests the token endpoint on load',
    calls.some((c) => c.url === 'api/csrf-token.php'));

  const tokenCall = calls.find((c) => c.url === 'api/csrf-token.php');
  check('token request uses GET', tokenCall.options.method === 'GET');
  check('token request uses same-origin credentials',
    tokenCall.options.credentials === 'same-origin');
  check('token request uses cache: no-store', tokenCall.options.cache === 'no-store');

  check('csrf_token populated',
    doc.getElementById('csrfToken').value === 'a'.repeat(64));
  check('submission_token populated',
    doc.getElementById('submissionToken').value === 'b'.repeat(64));
  check('submit button enabled once tokens arrive',
    doc.getElementById('submitBtn').disabled === false);
  window.close();
}

{
  const { window, doc } = await makePage({ token: 'network-error' });
  check('submit stays disabled when tokens fail',
    doc.getElementById('submitBtn').disabled === true);
  check('token failure shows a non-sensitive error',
    /could not prepare the form securely/i.test(statusText(doc)));
  check('token failure offers the support address',
    statusText(doc).includes('chris@jacsolarcorp.com'));
  window.close();
}

// ── 2. Client-side validation ───────────────────────────────────────────────

console.log('\n\u2500\u2500 Client-side validation \u2500\u2500');
{
  const { window, doc, calls } = await makePage();
  await submit(window, doc);

  check('empty form does not reach the server',
    !calls.some((c) => c.url.includes('submit-quote')));
  check('name error shown', /full name/i.test(fieldError(doc, 'full_name')));
  check('contact error shown', /Philippine/i.test(fieldError(doc, 'contact_number')));
  check('email error shown', /email/i.test(fieldError(doc, 'email')));
  check('location error shown', fieldError(doc, 'project_location').length > 0);
  check('provider error shown', fieldError(doc, 'electricity_provider').length > 0);
  check('property type error shown', fieldError(doc, 'property_type').length > 0);
  check('bill range error shown', fieldError(doc, 'bill_range').length > 0);
  check('bill upload error shown', /electricity bill/i.test(fieldError(doc, 'electricity_bill')));
  check('consent error shown', /consent/i.test(fieldError(doc, 'processing_consent')));
  check('invalid fields marked aria-invalid',
    doc.querySelector('[name="full_name"]').getAttribute('aria-invalid') === 'true');
  check('summary status announced', /review the highlighted fields/i.test(statusText(doc)));
  window.close();
}

{
  const { window, doc, calls } = await makePage();
  fillValid(window, doc, { withFile: false });
  await submit(window, doc);
  check('missing bill blocks submission',
    !calls.some((c) => c.url.includes('submit-quote')));
  check('missing bill reports on the right field',
    fieldError(doc, 'electricity_bill').length > 0);
  window.close();
}

{
  const { window, doc } = await makePage();
  const input = doc.getElementById('billUpload');
  attachFile(window, input, { name: 'virus.exe', type: 'application/x-msdownload' });
  check('disallowed file type rejected at selection',
    /PDF, JPG, or PNG/i.test(fieldError(doc, 'electricity_bill')));
  check('rejected file cleared from the input', input.value === '');
  window.close();
}

{
  const { window, doc } = await makePage();
  const input = doc.getElementById('billUpload');
  attachFile(window, input, { name: 'huge.pdf', type: 'application/pdf', size: 12 * 1024 * 1024 });
  check('oversized file rejected at selection',
    /maximum size is 10 MB/i.test(fieldError(doc, 'electricity_bill')));
  window.close();
}

{
  const { window, doc } = await makePage();
  const input = doc.getElementById('billUpload');
  attachFile(window, input, { name: 'bill.jpg', type: 'image/jpeg', size: 500000 });
  check('accepted file shows its name',
    doc.getElementById('uploadFilename').textContent.includes('bill.jpg'));
  check('accepted file shows its size',
    /KB|MB/.test(doc.getElementById('uploadFilename').textContent));
  check('replace control becomes available',
    doc.getElementById('uploadActions').classList.contains('visible'));

  doc.getElementById('removeFileBtn').click();
  check('remove clears the selection', input.value === '');
  check('remove hides the filename',
    doc.getElementById('uploadFilename').style.display === 'none');
  window.close();
}

{
  const { window, doc, calls } = await makePage();
  fillValid(window, doc);
  doc.querySelector('[name="full_name"]').value = 'A';
  await submit(window, doc);
  check('name shorter than 2 characters blocked',
    !calls.some((c) => c.url.includes('submit-quote')));
  window.close();
}

{
  const { window, doc, calls } = await makePage();
  fillValid(window, doc);
  doc.querySelector('[name="message"]').value = 'x'.repeat(2001);
  await submit(window, doc);
  check('over-long message blocked',
    !calls.some((c) => c.url.includes('submit-quote')));
  check('over-long message reported on its field',
    fieldError(doc, 'message').length > 0);
  window.close();
}

{
  const { window, doc, calls } = await makePage({
    submit: { status: 'success', reference_number: 'JACQ-20260804-00001', notice: null },
  });
  fillValid(window, doc);
  doc.querySelector('[name="contact_number"]').value = '+63 917 123 4567';
  await submit(window, doc);
  check('international +63 format accepted',
    calls.some((c) => c.url.includes('submit-quote')));
  window.close();
}

// ── 3. Submission mechanics ─────────────────────────────────────────────────

console.log('\n\u2500\u2500 Submission mechanics \u2500\u2500');
{
  const { window, doc, calls } = await makePage({
    submit: { status: 'success', reference_number: 'JACQ-20260804-00001', notice: null },
  });
  fillValid(window, doc);
  await submit(window, doc);

  const post = calls.find((c) => c.url === 'api/submit-quote.php');
  check('posts to the submit endpoint', Boolean(post));
  check('uses POST', post.options.method === 'POST');
  check('uses same-origin credentials', post.options.credentials === 'same-origin');
  check('sends FormData', post.options.body instanceof window.FormData);
  check('does not set Content-Type manually',
    !Object.keys(post.options.headers || {}).some((h) => h.toLowerCase() === 'content-type'));

  const body = post.options.body;
  check('csrf_token included', body.get('csrf_token') === 'a'.repeat(64));
  check('submission_token included', body.get('submission_token') === 'b'.repeat(64));
  check('honeypot sent empty', body.get('website') === '');
  check('processing_consent sent as 1', body.get('processing_consent') === '1');
  check('marketing_consent omitted when unticked', body.get('marketing_consent') === null);
  window.close();
}

{
  const { window, doc, calls } = await makePage({
    submit: { status: 'success', reference_number: 'JACQ-20260804-00002', notice: null },
  });
  fillValid(window, doc);
  doc.querySelector('[name="marketing_consent"]').checked = true;
  await submit(window, doc);
  const post = calls.find((c) => c.url === 'api/submit-quote.php');
  check('marketing_consent sent as 1 when ticked',
    post.options.body.get('marketing_consent') === '1');
  window.close();
}

{
  // A submit that never resolves, to observe the in-flight state.
  let release;
  const pending = new Promise((resolve) => { release = resolve; });

  const dom = new JSDOM(html, {
    url: 'https://staging.jacsolarcorp.com/',
    runScripts: 'dangerously',
    pretendToBeVisual: true,
    beforeParse(window) {
      window.HTMLElement.prototype.scrollIntoView = function () {};
      window.IntersectionObserver = class {
        observe() {}
        unobserve() {}
        disconnect() {}
      };
      window.fetch = (url) => {
        if (url.includes('csrf-token.php')) {
          return Promise.resolve({
            ok: true,
            json: () => Promise.resolve({
              status: 'success',
              csrf_token: 'a'.repeat(64),
              submission_token: 'b'.repeat(64),
            }),
          });
        }
        submitCount += 1;
        return pending.then(() => ({
          ok: true,
          json: () => Promise.resolve({ status: 'success', reference_number: 'JACQ-1', notice: null }),
        }));
      };
    },
  });
  let submitCount = 0;
  const { window } = dom;
  const doc = window.document;
  await new Promise((r) => window.addEventListener('load', r));
  await tick(window, 3);

  fillValid(window, doc);
  await submit(window, doc, 2);

  const btn = doc.getElementById('submitBtn');
  check('button disabled while sending', btn.disabled === true);
  check('button announces busy state', btn.getAttribute('aria-busy') === 'true');
  check('button shows a sending label', /Sending/i.test(btn.textContent));

  await submit(window, doc, 2);
  await submit(window, doc, 2);
  check('repeat clicks do not fire extra requests', submitCount === 1,
    `fired ${submitCount}`);

  release();
  await tick(window, 4);
  check('button re-enabled after the response', btn.disabled === false);
  window.close();
}

// ── 4. Response states ──────────────────────────────────────────────────────

console.log('\n\u2500\u2500 Response states \u2500\u2500');
{
  const { window, doc, calls } = await makePage({
    submit: { status: 'success', reference_number: 'JACQ-20260804-00007', notice: null },
  });
  fillValid(window, doc);
  await submit(window, doc);

  const text = statusText(doc);
  check('success wording shown',
    text.includes('Thank you! Your Free Quote request has been received.'));
  check('reference number shown', text.includes('JACQ-20260804-00007'));
  check('acknowledgment confirmed', /confirmation email/i.test(text));
  check('response time stated', /one to two business days/i.test(text));
  check('success does not offer the support address',
    !text.includes('chris@jacsolarcorp.com'));
  check('status region marked success',
    doc.getElementById('formStatus').className.includes('status-success'));
  check('form reset after success',
    doc.querySelector('[name="full_name"]').value === '');
  check('fresh tokens requested after success',
    calls.filter((c) => c.url === 'api/csrf-token.php').length >= 2);
  window.close();
}

{
  const { window, doc } = await makePage({
    submit: {
      status: 'success',
      reference_number: 'JACQ-20260804-00008',
      notice: 'Your request is recorded and your reference number is confirmed. The email confirmation may be delayed.',
    },
  });
  fillValid(window, doc);
  await submit(window, doc);

  const text = statusText(doc);
  check('email-delay notice displayed', /email confirmation may be delayed/i.test(text));
  check('delayed email still treated as success',
    doc.getElementById('formStatus').className.includes('status-success'));
  check('delayed email still shows the reference', text.includes('JACQ-20260804-00008'));
  check('delayed email does not offer the support address',
    !text.includes('chris@jacsolarcorp.com'));
  window.close();
}

{
  const { window, doc, calls } = await makePage({
    submit: {
      status: 'already_received',
      reference_number: 'JACQ-20260804-00003',
      message: 'We already received this request.',
    },
  });
  fillValid(window, doc);
  await submit(window, doc);

  const text = statusText(doc);
  check('already_received explained', /already/i.test(text));
  check('already_received shows the original reference',
    text.includes('JACQ-20260804-00003'));
  check('already_received is not styled as an error',
    doc.getElementById('formStatus').className.includes('status-info'));
  check('already_received does not resubmit',
    calls.filter((c) => c.url === 'api/submit-quote.php').length === 1);
  window.close();
}

{
  const { window, doc } = await makePage({
    submit: {
      status: 'validation_error',
      message: 'Please review the highlighted fields and try again.',
      errors: { email: 'Please enter a valid email address.' },
    },
  });
  fillValid(window, doc);
  await submit(window, doc);

  check('server field error mapped to its field',
    fieldError(doc, 'email') === 'Please enter a valid email address.');
  check('server error marks the field invalid',
    doc.querySelector('[name="email"]').getAttribute('aria-invalid') === 'true');
  check('entered data preserved after validation error',
    doc.querySelector('[name="full_name"]').value === 'Juan Dela Cruz');
  check('focus moved to the offending field',
    doc.activeElement === doc.querySelector('[name="email"]'));
  window.close();
}

{
  const { window, doc } = await makePage({
    submit: {
      status: 'upload_error',
      message: 'There was a problem with the uploaded electricity bill.',
      errors: { electricity_bill: 'Only PDF, JPG, or PNG files are accepted.' },
    },
  });
  fillValid(window, doc);
  await submit(window, doc);
  check('upload_error mapped to the bill field',
    fieldError(doc, 'electricity_bill').includes('Only PDF'));
  check('upload_error preserves other entered data',
    doc.querySelector('[name="project_location"]').value === 'Davao City');
  window.close();
}

{
  const { window, doc, calls } = await makePage({
    submit: {
      status: 'csrf_error',
      message: 'Your session expired. Please refresh the page and try again.',
      errors: [],
    },
  });
  fillValid(window, doc);
  const before = calls.filter((c) => c.url === 'api/csrf-token.php').length;
  await submit(window, doc);

  check('csrf_error message shown', /session expired/i.test(statusText(doc)));
  check('csrf_error triggers a token refresh',
    calls.filter((c) => c.url === 'api/csrf-token.php').length > before);
  check('csrf_error preserves entered data',
    doc.querySelector('[name="email"]').value === 'juan@example.com');
  window.close();
}

{
  const { window, doc } = await makePage({
    submit: {
      status: 'rate_limited',
      message: 'Too many submissions from this connection.',
      retry_after_seconds: 900,
      errors: [],
    },
  });
  fillValid(window, doc);
  await submit(window, doc);

  const text = statusText(doc);
  check('rate_limited message shown', /Too many submissions/i.test(text));
  check('rate_limited converts seconds to minutes', /15 minutes/.test(text));
  check('rate_limited offers the support address',
    text.includes('chris@jacsolarcorp.com'));
  window.close();
}

{
  const { window, doc } = await makePage({
    submit: {
      status: 'server_error',
      message: 'We could not process your request right now. Please try again shortly or contact us directly.',
      errors: [],
    },
  });
  fillValid(window, doc);
  await submit(window, doc);

  const text = statusText(doc);
  check('server_error message shown', /could not process/i.test(text));
  check('server_error offers the support address',
    text.includes('chris@jacsolarcorp.com'));
  check('server_error preserves entered data',
    doc.querySelector('[name="full_name"]').value === 'Juan Dela Cruz');
  window.close();
}

{
  const { window, doc } = await makePage({ submit: 'network-error' });
  fillValid(window, doc);
  await submit(window, doc);

  const text = statusText(doc);
  check('network failure reported clearly', /Connection problem/i.test(text));
  check('network failure states confirmation is uncertain',
    /could not confirm whether your request reached our server/i.test(text));
  check('network failure explains duplicate-safe retry',
    /duplicate protection/i.test(text));
  check('network failure re-enables the button',
    doc.getElementById('submitBtn').disabled === false);
  window.close();
}

// ── 5. Accessibility behaviour ──────────────────────────────────────────────

console.log('\n\u2500\u2500 Accessibility behaviour \u2500\u2500');
{
  const { window, doc } = await makePage({
    submit: { status: 'success', reference_number: 'JACQ-20260804-00009', notice: null },
  });
  const status = doc.getElementById('formStatus');
  check('status region is a live region', status.getAttribute('aria-live') === 'polite');
  check('status region has role=status', status.getAttribute('role') === 'status');
  check('status region hidden before any response', status.hidden === true);

  fillValid(window, doc);
  await submit(window, doc);

  check('status region revealed after response', status.hidden === false);
  check('focus moved to the status region', doc.activeElement === status);
  window.close();
}

{
  const { window, doc } = await makePage();
  await submit(window, doc);
  check('field error clears once corrected', (() => {
    const field = doc.querySelector('[name="full_name"]');
    const had = fieldError(doc, 'full_name').length > 0;
    field.value = 'Juan Dela Cruz';
    field.dispatchEvent(new window.Event('input', { bubbles: true }));
    return had && fieldError(doc, 'full_name') === '';
  })());
  window.close();
}

console.log('\n\u2500'.repeat(1) + '\u2500'.repeat(31));
console.log(`  ${passed} passed, ${failed} failed\n`);

process.exit(failed === 0 ? 0 : 1);
