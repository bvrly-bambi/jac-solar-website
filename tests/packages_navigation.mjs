import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const files = ['index.html', 'staging-app/index.html'];
const packageNames = [
  'Sunrise 3 HB',
  'Sunbeam 6 HB',
  'Sunburst 8 HB',
  'Sunflare 10 HB',
  'Suncrest 12 HB',
  'Sunforge 16 HB',
  'Sunforge 18 HB',
  'Sunforge 20 HB',
  'Sunforge 24 HB',
];
const packageIds = [
  'sunrise-3',
  'sunbeam-6',
  'sunburst-8',
  'sunflare-10',
  'suncrest-12',
  'sunforge-16',
  'sunforge-18',
  'sunforge-20',
  'sunforge-24',
];
const packageRanges = [
  'Below ₱5,000',
  '₱5,000–₱8,000',
  '₱6,000–₱10,000',
  '₱8,000–₱12,000',
  '₱10,000–₱14,000',
  '₱14,000–₱18,000',
  '₱16,000–₱22,000',
  '₱18,000–₱24,000',
  '₱20,000–₱30,000',
];
const boundaries = [
  [4999, ['sunrise-3']],
  [5000, ['sunbeam-6']],
  [6000, ['sunbeam-6', 'sunburst-8']],
  [8000, ['sunburst-8', 'sunbeam-6', 'sunflare-10']],
  [10000, ['sunflare-10', 'sunburst-8', 'suncrest-12']],
  [12000, ['suncrest-12', 'sunflare-10']],
  [14000, ['suncrest-12', 'sunforge-16']],
  [16000, ['sunforge-16', 'sunforge-18']],
  [18000, ['sunforge-18', 'sunforge-16', 'sunforge-20']],
  [20000, ['sunforge-18', 'sunforge-20', 'sunforge-24']],
  [22000, ['sunforge-20', 'sunforge-18', 'sunforge-24']],
  [24000, ['sunforge-24', 'sunforge-20']],
  [30000, ['sunforge-24']],
  [30001, []],
];

function packageFinderFrom(html, file) {
  const match = html.match(/\/\* PACKAGE_FINDER_START \*\/([\s\S]*?)\/\* PACKAGE_FINDER_END \*\//);
  assert.ok(match, `${file}: package finder API markers are present`);
  const context = { window: {} };
  vm.runInNewContext(match[1], context, { filename: file });
  return context.window.JACPackageFinder;
}

function classList(initial = []) {
  const names = new Set(initial);
  return {
    add: (...values) => values.forEach((value) => names.add(value)),
    remove: (...values) => values.forEach((value) => names.delete(value)),
    contains: (value) => names.has(value),
    toggle(value, force) {
      const enabled = force === undefined ? !names.has(value) : Boolean(force);
      if (enabled) names.add(value);
      else names.delete(value);
      return enabled;
    },
  };
}

function packageUiFrom(html, file) {
  const ids = [...html.matchAll(/data-package-id="([^"]+)"/g)].map((match) => match[1]);
  const cards = ids.map((id) => {
    const label = { textContent: '' };
    const attributes = new Map();
    return {
      id,
      style: {},
      classList: classList(),
      label,
      setAttribute: (name, value) => attributes.set(name, value),
      removeAttribute: (name) => attributes.delete(name),
      getAttribute: (name) => attributes.get(name),
      querySelector: (selector) => selector === '.pkg-match-label' ? label : null,
    };
  });
  const hint = { textContent: '' };
  const banner = { classList: classList(), scrollIntoView() {} };
  const interactive = (value = '') => ({
    value,
    max: '32000',
    listeners: new Map(),
    addEventListener(name, listener) { this.listeners.set(name, listener); },
  });
  const slider = interactive('0');
  const input = interactive('');
  const button = interactive();
  const elements = new Map([
    ['billSlider', slider],
    ['billFinder', input],
    ['findPackagesBtn', button],
    ['billHint', hint],
    ['pkgCustomBanner', banner],
    ...cards.map((card) => [`card-${card.id}`, card]),
  ]);
  const document = {
    querySelectorAll: (selector) => selector === '.pkg-card' ? cards : [],
    getElementById: (id) => elements.get(id) || null,
  };
  const start = html.indexOf('/* PACKAGE_FINDER_START */');
  const end = html.indexOf('const obs=', start);
  assert.ok(start >= 0 && end > start, `${file}: package UI script is extractable`);
  const context = { window: {}, document };
  vm.runInNewContext(html.slice(start, end), context, { filename: file });
  return { context, cards, hint, banner, input, button };
}

function navigationFrom(html, file) {
  const documentListeners = new Map();
  const windowListeners = new Map();
  let document;
  const element = () => ({
    attributes: new Map(),
    listeners: new Map(),
    classList: classList(),
    setAttribute(name, value) { this.attributes.set(name, value); },
    addEventListener(name, listener) { this.listeners.set(name, listener); },
    focus() { document.activeElement = this; },
  });
  const toggle = element();
  const toggleBar = {};
  toggle.contains = (target) => target === toggle || target === toggleBar;
  const links = [element(), element(), element()];
  const panel = element();
  panel.querySelectorAll = () => links;
  panel.contains = (target) => target === panel || links.includes(target);
  document = {
    activeElement: null,
    body: { style: {} },
    documentElement: { scrollTop: 0 },
    getElementById(id) {
      if (id === 'navToggle') return toggle;
      if (id === 'navMobile') return panel;
      return null;
    },
    addEventListener(name, listener) { documentListeners.set(name, listener); },
  };
  const window = {
    innerWidth: 800,
    addEventListener(name, listener) { windowListeners.set(name, listener); },
  };
  const start = html.indexOf('/* NAV_REVAMP_START */');
  const endMarker = '/* NAV_REVAMP_END */';
  const end = html.indexOf(endMarker, start);
  assert.ok(start >= 0 && end > start, `${file}: navigation script is extractable`);
  vm.runInNewContext(html.slice(start, end + endMarker.length), { window, document }, { filename: file });
  return { document, window, toggle, toggleBar, panel, links, documentListeners, windowListeners };
}

for (const file of files) {
  const html = fs.readFileSync(file, 'utf8');
  const packageSection = html.slice(html.indexOf('<!-- PACKAGES -->'), html.indexOf('<!-- ROI / THE MATH SECTION -->'));
  const navSection = html.slice(html.indexOf('<!-- NAV -->'), html.indexOf('<!-- HERO -->'));
  const finder = packageFinderFrom(html, file);
  const canonicalIds = packageIds;

  assert.equal(finder.catalog.length, 9, `${file}: exactly 9 packages in finder catalog`);
  assert.deepEqual(Array.from(finder.catalog, (entry) => entry.id), canonicalIds,
    `${file}: finder catalog remains in canonical order`);
  for (const name of packageNames) assert.match(packageSection, new RegExp(name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')), `${file}: ${name}`);
  for (const range of packageRanges) assert.match(packageSection, new RegExp(range.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')), `${file}: ${range}`);
  for (const [bill, expected] of boundaries) {
    assert.deepEqual(Array.from(finder.getMatches(bill), (entry) => entry.id), expected, `${file}: bill ${bill}`);
  }

  const packageUi = packageUiFrom(html, file);
  assert.deepEqual(packageUi.cards.map((card) => card.id), canonicalIds,
    `${file}: card DOM starts in canonical order`);
  for (const [bill, expected] of boundaries) {
    packageUi.context.highlightPackage(bill);
    const qualifying = packageUi.cards
      .filter((card) => card.classList.contains('is-primary-match') || card.classList.contains('is-range-match'))
      .sort((a, b) => Number(a.getAttribute('data-match-order')) - Number(b.getAttribute('data-match-order')))
      .map((card) => card.id);
    assert.deepEqual(qualifying, expected, `${file}: qualifying states for bill ${bill}`);
    const primary = packageUi.cards.filter((card) => card.classList.contains('is-primary-match'));
    assert.deepEqual(primary.map((card) => card.id), expected.length ? [expected[0]] : [],
      `${file}: closest-midpoint primary for bill ${bill}`);
    assert.deepEqual(packageUi.cards.map((card) => card.id), canonicalIds,
      `${file}: card DOM order is stable for bill ${bill}`);
    assert.ok(packageUi.cards.every((card) => !Object.hasOwn(card.style, 'order')),
      `${file}: no inline catalog ordering for bill ${bill}`);
    assert.equal(packageUi.banner.classList.contains('is-emphasized'), bill > 30000,
      `${file}: custom treatment for bill ${bill}`);
  }
  packageUi.input.value = '8000';
  packageUi.button.listeners.get('click')();
  assert.equal(packageUi.cards.find((card) => card.id === 'sunburst-8').classList.contains('is-primary-match'), true,
    `${file}: finder action applies the closest-midpoint result`);
  packageUi.context.highlightPackage('');
  assert.equal(packageUi.banner.classList.contains('is-emphasized'), false,
    `${file}: clearing the bill resets custom treatment`);
  assert.ok(packageUi.cards.every((card) =>
    !card.classList.contains('is-primary-match') &&
    !card.classList.contains('is-range-match') &&
    !card.classList.contains('is-subdued')),
  `${file}: clearing the bill resets all match states`);

  assert.match(packageSection, /Find My Packages/);
  assert.match(packageSection, /Monthly bill guide/);
  assert.match(packageSection, /Typical loads/);
  assert.match(packageSection, /Fully customizable based on site assessment/);
  assert.match(packageSection, /Grid-Tie \/ Hybrid/);
  assert.match(packageSection, /Discuss My Solar Needs/);
  assert.match(packageSection, /pkg-use-icon/);
  assert.doesNotMatch(packageSection, /Download Package Specs/);
  assert.doesNotMatch(packageSection, /For Homes|For Business|typical Filipino homes|businesses/i);
  assert.doesNotMatch(`${packageSection}\n${navSection}`, /Get My\s+.*FREE\s+Quote|Get a free quote|Talk to an Engineer|Book a free assessment/);
  assert.doesNotMatch(packageSection, /25-Yr Warranty|25-Year Warranty|25-Year Lifespan|Net Metering Ready/);
  assert.match(packageSection, /25-Yr Panel Warranty/);
  assert.match(packageSection, /25-Year Panel Performance Warranty/);
  assert.doesNotMatch(html, /PKG\.find\s*\(/);
  assert.doesNotMatch(html, /style\.order/);
  assert.match(html, /styles\/main\.css\?v=packages-nav-v2/);
  assert.match(packageSection, /id="billFinder"[^>]*oninput="syncBillSlider\(this\.value\)"/);
  assert.match(packageSection, /id="billSlider"[^>]*oninput="syncBillInput\(this\.value\)"/);
  assert.doesNotMatch(packageSection, /oninput="[^"]*highlightPackage/);

  assert.match(navSection, /<button class="nav-toggle"/);
  assert.match(navSection, /aria-expanded="false"/);
  assert.match(navSection, /aria-controls="navMobile"/);
  assert.match(navSection, /aria-label="Open navigation menu"/);
  assert.match(html, /NAV_REVAMP_START[\s\S]*Escape[\s\S]*resize/);
  assert.match(html, /!toggle\.contains\(event\.target\)[\s\S]*!panel\.contains\(event\.target\)/);
  assert.match(html, /function setOpen\(open, returnFocus\)/);
  assert.match(html, /toggle\.addEventListener\('click'/);
  assert.match(html, /Array\.prototype\.forEach\.call\(focusable/);
  assert.match(html, /event\.key === 'Escape'/);
  assert.match(html, /window\.innerWidth > 960/);

  const nav = navigationFrom(html, file);
  nav.toggle.listeners.get('click')({ target: nav.toggleBar });
  nav.documentListeners.get('click')({ target: nav.toggleBar });
  assert.equal(nav.panel.classList.contains('open'), true,
    `${file}: clicking a hamburger bar keeps the menu open`);
  assert.equal(nav.toggle.attributes.get('aria-expanded'), 'true',
    `${file}: opening updates aria-expanded`);

  nav.documentListeners.get('keydown')({ key: 'Escape', shiftKey: false, preventDefault() {} });
  assert.equal(nav.panel.classList.contains('open'), false, `${file}: Escape closes`);
  assert.equal(nav.document.activeElement, nav.toggle, `${file}: Escape restores toggle focus`);

  nav.toggle.listeners.get('click')({ target: nav.toggle });
  nav.links[1].listeners.get('click')({ target: nav.links[1] });
  assert.equal(nav.panel.classList.contains('open'), false, `${file}: link click closes`);

  nav.toggle.listeners.get('click')({ target: nav.toggle });
  nav.documentListeners.get('click')({ target: {} });
  assert.equal(nav.panel.classList.contains('open'), false, `${file}: outside click closes`);

  nav.toggle.listeners.get('click')({ target: nav.toggle });
  nav.window.innerWidth = 961;
  nav.windowListeners.get('resize')();
  assert.equal(nav.panel.classList.contains('open'), false, `${file}: desktop resize resets`);
}

const css = fs.readFileSync('styles/main.css', 'utf8');
assert.match(css, /\.nav-toggle[\s\S]*width:44px[\s\S]*height:44px/);
assert.match(css, /@media \(max-width:960px\)/);
assert.equal((css.match(/^\.nav-toggle\{/gm) || []).length, 1,
  'one authoritative base nav-toggle rule');
assert.equal((css.match(/^\.nav-mobile\{/gm) || []).length, 1,
  'one authoritative base nav-mobile rule');
assert.equal((css.match(/Authoritative mobile navigation implementation/g) || []).length, 1,
  'one authoritative mobile navigation block');
assert.match(css, /\.nav-toggle\{[^}]*flex:0 0 44px/);
assert.match(css, /\.nav-brand\{[^}]*min-width:0/);
assert.match(css, /grid-template-columns:repeat\(4,minmax\(0,1fr\)\)/);
assert.match(css, /\.bill-finder\{[^}]*background:var\(--navy\)/);
assert.match(css, /\.pkg-card\{[^}]*background:#fff/);
assert.match(css, /scroll-snap-type:x mandatory/);
assert.match(css, /flex:0 0 84vw/);
assert.match(css, /prefers-reduced-motion:reduce/);
assert.doesNotMatch(css, /\.bill-selector|\.pkg-card\.popular|\.popular-badge|\.pkg-package-name|\.pkg-savings|\.pkg-appliances|\.btn-pkg-secondary|\.pkg-header|\.pkg-baseline-note/);

const stagingCss = fs.readFileSync('staging-app/styles/main.css', 'utf8');
assert.equal(stagingCss, css, 'root and staging CSS are byte-identical');
assert.equal(fs.readFileSync('staging-app/index.html', 'utf8'), fs.readFileSync('index.html', 'utf8'),
  'root and staging HTML are byte-identical');

console.log('packages_navigation: PASS');
