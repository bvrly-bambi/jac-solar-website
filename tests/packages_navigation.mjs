import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const files = ['index.html', 'staging-app/index.html'];
const packageFacts = [
  { id: 'sunrise-3', name: 'Sunrise 3 HB', category: 'homes', range: 'Below ₱5,000', uses: ['Lighting', 'Refrigerator', 'TV', 'WiFi Router'] },
  { id: 'sunbeam-6', name: 'Sunbeam 6 HB', category: 'homes', range: '₱5,000–₱8,000', uses: ['Aircon', 'Refrigerator', 'Computer', 'TV'] },
  { id: 'sunburst-8', name: 'Sunburst 8 HB', category: 'homes', range: '₱6,000–₱10,000', uses: ['Aircon', 'Refrigerator', 'Washing Machine', 'TV'] },
  { id: 'sunflare-10', name: 'Sunflare 10 HB', category: 'homes', range: '₱8,000–₱12,000', uses: ['Aircon', 'Washing Machine', 'Computer', 'TV'] },
  { id: 'suncrest-12', name: 'Suncrest 12 HB', category: 'business', range: '₱10,000–₱14,000', uses: ['Commercial / Industrial', 'Water Pump', 'Computer', 'Washing Machine'] },
  { id: 'sunforge-16', name: 'Sunforge 16 HB', category: 'business', range: '₱14,000–₱18,000', uses: ['Commercial / Industrial', 'Aircon', 'Computer', 'Washing Machine'] },
  { id: 'sunforge-18', name: 'Sunforge 18 HB', category: 'business', range: '₱16,000–₱22,000', uses: ['Commercial / Industrial', 'Aircon', 'Computer', 'Water Pump'] },
  { id: 'sunforge-20', name: 'Sunforge 20 HB', category: 'business', range: '₱18,000–₱24,000', uses: ['Commercial / Industrial', 'Water Pump', 'Computer', 'Aircon'] },
  { id: 'sunforge-24', name: 'Sunforge 24 HB', category: 'business', range: '₱20,000–₱30,000', uses: ['Commercial / Industrial', 'Agricultural / Farm', 'Water Pump', 'Computer'] },
];
const packageIds = packageFacts.map((entry) => entry.id);
const homesIds = packageFacts.filter((entry) => entry.category === 'homes').map((entry) => entry.id);
const businessIds = packageFacts.filter((entry) => entry.category === 'business').map((entry) => entry.id);
const boundaries = [
  [4999, ['sunrise-3']],
  [5000, ['sunbeam-6']],
  [6000, ['sunbeam-6', 'sunburst-8']],
  [8000, ['sunburst-8', 'sunbeam-6', 'sunflare-10']],
  [9000, ['sunburst-8', 'sunflare-10']],
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
const freeQuoteRanges = [
  'Below ₱5,000',
  '₱5,000–₱7,999',
  '₱8,000–₱11,999',
  '₱12,000–₱17,999',
  '₱18,000–₱23,999',
  '₱24,000–₱29,999',
  '₱30,000 and above',
];

function escapeRegExp(value) {
  return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

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
  const articleTags = [...html.matchAll(/<article class="pkg-card"[^>]*data-package-id="([^"]+)"[^>]*data-category="([^"]+)"([^>]*)>/g)];
  const cards = articleTags.map((match) => {
    const attributes = new Map([['data-category', match[2]]]);
    return {
      id: match[1],
      hidden: /\shidden(?:\s|>)/.test(match[3] + '>'),
      classList: classList(),
      scrollIntoView() {},
      getAttribute: (name) => attributes.get(name) ?? null,
      setAttribute: (name, value) => attributes.set(name, value),
      removeAttribute: (name) => attributes.delete(name),
    };
  });
  const interactive = (value = '') => ({
    value,
    max: '32000',
    attributes: new Map(),
    listeners: new Map(),
    classList: classList(),
    addEventListener(name, listener) { this.listeners.set(name, listener); },
    setAttribute(name, value) { this.attributes.set(name, value); },
  });
  const slider = interactive('0');
  const input = interactive('');
  const button = interactive();
  const homesTab = interactive();
  homesTab.classList.add('active');
  const businessTab = interactive();
  const note = { textContent: 'A recommended starting point — not a final quote.' };
  const count = { textContent: '4 packages for homes' };
  const grid = { classList: classList() };
  const banner = { classList: classList(), scrollIntoView() {} };
  const elements = new Map([
    ['billSlider', slider],
    ['billFinder', input],
    ['findPackagesBtn', button],
    ['tabHomes', homesTab],
    ['tabBusiness', businessTab],
    ['billFinderHelp', note],
    ['packageCount', count],
    ['pkgGrid', grid],
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
  return { context, cards, input, slider, button, homesTab, businessTab, note, count, grid, banner };
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

  assert.equal(finder.catalog.length, 9, `${file}: exactly 9 packages`);
  assert.deepEqual(Array.from(finder.catalog, (entry) => entry.id), packageIds, `${file}: canonical catalog order`);
  assert.deepEqual(Array.from(finder.catalog.filter((entry) => entry.category === 'homes'), (entry) => entry.id), homesIds,
    `${file}: exact Homes mapping`);
  assert.deepEqual(Array.from(finder.catalog.filter((entry) => entry.category === 'business'), (entry) => entry.id), businessIds,
    `${file}: exact Business mapping`);
  for (const [bill, expected] of boundaries) {
    assert.deepEqual(Array.from(finder.getMatches(bill), (entry) => entry.id), expected, `${file}: qualifying ranges at ${bill}`);
    assert.equal(finder.getRecommendation(bill)?.id ?? null, expected[0] ?? null, `${file}: recommendation at ${bill}`);
  }
  assert.equal(finder.getRecommendation(9000).id, 'sunburst-8', `${file}: 9000 midpoint tie goes smaller`);

  for (const fact of packageFacts) {
    const start = packageSection.indexOf(`id="card-${fact.id}"`);
    const end = packageSection.indexOf('</article>', start);
    const card = packageSection.slice(start, end);
    assert.ok(start >= 0 && end > start, `${file}: ${fact.id} card exists`);
    assert.match(card, new RegExp(escapeRegExp(fact.name)), `${file}: ${fact.name}`);
    assert.match(card, new RegExp(escapeRegExp(fact.range)), `${file}: ${fact.range}`);
    assert.match(card, new RegExp(`data-category="${fact.category}"`), `${file}: ${fact.id} category`);
    for (const use of fact.uses) assert.match(card, new RegExp(escapeRegExp(use)), `${file}: ${fact.id} ${use}`);
  }

  assert.match(packageSection, /Solar, engineered to <em>your consumption\.<\/em>/);
  assert.match(packageSection, /For Homes/);
  assert.match(packageSection, /For Business/);
  assert.match(packageSection, /4 packages for homes/);
  assert.match(packageSection, /Find my package/);
  assert.equal((packageSection.match(/Typical uses/g) || []).length, 9, `${file}: Typical uses on every card`);
  assert.equal((packageSection.match(/Get a Free Quote/g) || []).length, 9, `${file}: CTA on every card`);
  assert.equal((packageSection.match(/Fully customizable based on site assessment/g) || []).length, 9,
    `${file}: microcopy on every card`);
  assert.match(packageSection, /Commercial \/ Industrial/);
  assert.match(packageSection, /Agricultural \/ Farm/);
  assert.match(packageSection, /Grid-Tie \/ Hybrid/);
  assert.match(packageSection, /25-Yr Panel Warranty/);
  assert.match(packageSection, /Discuss My Solar Needs/);
  assert.match(packageSection, /0920 252 8376/);
  assert.doesNotMatch(packageSection, /Can comfortably power|Typical loads|Net Metering Ready|25-Yr Warranty|Book a free assessment/);
  assert.doesNotMatch(html, /max:\s*99999|style\.order/);
  assert.match(html, /styles\/main\.css\?v=packages-prototype-v3/);
  assert.doesNotMatch(packageSection, /oninput="[^"]*findPackage/);

  const quoteSelect = html.match(/<select[^>]*id="billRange"[^>]*>([\s\S]*?)<\/select>/);
  assert.ok(quoteSelect, `${file}: Free Quote bill range select exists`);
  const quoteOptions = [...quoteSelect[1].matchAll(/<option(?:\s[^>]*)?>([^<]*)<\/option>/g)]
    .map((match) => match[1]).filter(Boolean);
  assert.deepEqual(quoteOptions, ['Select range...', ...freeQuoteRanges], `${file}: Free Quote ranges unchanged`);

  const ui = packageUiFrom(html, file);
  assert.deepEqual(ui.cards.filter((card) => !card.hidden).map((card) => card.id), homesIds, `${file}: Homes visible initially`);
  assert.equal(ui.count.textContent, '4 packages for homes', `${file}: initial Homes count`);
  ui.context.syncBillInput('9000');
  assert.equal(ui.cards.some((card) => card.classList.contains('is-recommended')), false,
    `${file}: slider synchronization does not recommend`);

  ui.input.value = '9000';
  ui.button.listeners.get('click')();
  assert.deepEqual(ui.cards.filter((card) => card.classList.contains('is-recommended')).map((card) => card.id), ['sunburst-8'],
    `${file}: click creates one recommendation`);
  assert.equal(ui.grid.classList.contains('has-recommendation'), true, `${file}: click enables dimming state`);
  assert.match(ui.note.textContent, /At ₱9,000\/month, Sunburst 8 HB is a recommended starting package/);

  ui.input.value = '18000';
  ui.input.listeners.get('keydown')({ key: 'Enter' });
  assert.deepEqual(ui.cards.filter((card) => card.classList.contains('is-recommended')).map((card) => card.id), ['sunforge-18'],
    `${file}: Enter creates one Business recommendation`);
  assert.deepEqual(ui.cards.filter((card) => !card.hidden).map((card) => card.id), businessIds,
    `${file}: recommendation switches to Business`);
  assert.equal(ui.count.textContent, '5 packages for business', `${file}: Business count`);

  ui.homesTab.listeners.get('click')();
  assert.deepEqual(ui.cards.filter((card) => !card.hidden).map((card) => card.id), homesIds, `${file}: manual Homes tab`);
  assert.equal(ui.cards.some((card) => card.classList.contains('is-recommended')), false,
    `${file}: manual tab clears recommendation`);
  assert.equal(ui.grid.classList.contains('has-recommendation'), false, `${file}: manual tab clears dimming`);

  ui.input.value = '30001';
  ui.button.listeners.get('click')();
  assert.equal(ui.cards.some((card) => card.classList.contains('is-recommended')), false,
    `${file}: above 30000 recommends no card`);
  assert.equal(ui.banner.classList.contains('is-emphasized'), true, `${file}: above 30000 emphasizes custom assessment`);
  assert.match(ui.note.textContent, /custom assessment/);

  assert.match(navSection, /<button class="nav-toggle"/);
  assert.match(html, /NAV_REVAMP_START[\s\S]*Escape[\s\S]*resize/);
  assert.match(html, /!toggle\.contains\(event\.target\)[\s\S]*!panel\.contains\(event\.target\)/);
  const nav = navigationFrom(html, file);
  nav.toggle.listeners.get('click')({ target: nav.toggleBar });
  nav.documentListeners.get('click')({ target: nav.toggleBar });
  assert.equal(nav.panel.classList.contains('open'), true, `${file}: hamburger child click stays open`);
  nav.documentListeners.get('keydown')({ key: 'Escape', shiftKey: false, preventDefault() {} });
  assert.equal(nav.panel.classList.contains('open'), false, `${file}: Escape closes navigation`);
  assert.equal(nav.document.activeElement, nav.toggle, `${file}: Escape restores focus`);
  nav.toggle.listeners.get('click')({ target: nav.toggle });
  nav.links[1].listeners.get('click')({ target: nav.links[1] });
  assert.equal(nav.panel.classList.contains('open'), false, `${file}: link closes navigation`);
  nav.toggle.listeners.get('click')({ target: nav.toggle });
  nav.documentListeners.get('click')({ target: {} });
  assert.equal(nav.panel.classList.contains('open'), false, `${file}: outside click closes navigation`);
  nav.toggle.listeners.get('click')({ target: nav.toggle });
  nav.window.innerWidth = 961;
  nav.windowListeners.get('resize')();
  assert.equal(nav.panel.classList.contains('open'), false, `${file}: desktop resize resets navigation`);
}

const css = fs.readFileSync('styles/main.css', 'utf8');
assert.match(css, /\.pkg-category-toggle/);
assert.match(css, /\.pkg-recommendation-ribbon/);
assert.match(css, /\.pkg-grid\.has-recommendation \.pkg-card:not\(\.is-recommended\)/);
assert.match(css, /grid-template-columns:repeat\(4,minmax\(0,1fr\)\)/);
assert.match(css, /@media \(max-width:1080px\)[\s\S]*repeat\(2,minmax\(0,1fr\)\)/);
assert.match(css, /scroll-snap-type:x mandatory/);
assert.match(css, /flex:0 0 83vw/);
assert.match(css, /@media \(max-width:640px\)[\s\S]*has-recommendation[\s\S]*opacity:1/);
assert.match(css, /\.nav-toggle\{[^}]*flex:0 0 44px/);
assert.equal((css.match(/^\.nav-toggle\{/gm) || []).length, 1, 'one authoritative base nav-toggle rule');
assert.equal((css.match(/^\.nav-mobile\{/gm) || []).length, 1, 'one authoritative base nav-mobile rule');
assert.equal((css.match(/Authoritative mobile navigation implementation/g) || []).length, 1,
  'one authoritative mobile navigation block');
assert.match(css, /prefers-reduced-motion:reduce/);
assert.doesNotMatch(css, /\.pkg-match-label|\.is-primary-match|\.is-range-match|\.is-subdued/);

assert.equal(fs.readFileSync('staging-app/styles/main.css', 'utf8'), css, 'root/staging CSS byte-identical');
assert.equal(fs.readFileSync('staging-app/index.html', 'utf8'), fs.readFileSync('index.html', 'utf8'),
  'root/staging HTML byte-identical');
assert.equal(fs.readFileSync('staging-app/components/05_packages.html', 'utf8'),
  fs.readFileSync('components/05_packages.html', 'utf8'), 'root/staging package component byte-identical');

console.log('packages_navigation: PASS');
