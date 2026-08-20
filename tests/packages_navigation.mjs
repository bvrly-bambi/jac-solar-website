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

for (const file of files) {
  const html = fs.readFileSync(file, 'utf8');
  const packageSection = html.slice(html.indexOf('<!-- PACKAGES -->'), html.indexOf('</section>', html.indexOf('<!-- PACKAGES -->')));
  const navSection = html.slice(html.indexOf('<!-- NAV -->'), html.indexOf('<!-- HERO -->'));
  const finder = packageFinderFrom(html, file);

  assert.equal(finder.catalog.length, 9, `${file}: exactly 9 packages in finder catalog`);
  for (const name of packageNames) assert.match(packageSection, new RegExp(name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')), `${file}: ${name}`);
  for (const range of packageRanges) assert.match(packageSection, new RegExp(range.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')), `${file}: ${range}`);
  for (const [bill, expected] of boundaries) {
    assert.deepEqual(Array.from(finder.getMatches(bill), (entry) => entry.id), expected, `${file}: bill ${bill}`);
  }

  assert.match(packageSection, /Grid-Tie or Hybrid options available/);
  assert.match(packageSection, /Discuss My Solar Needs/);
  assert.doesNotMatch(packageSection, /Download Package Specs/);
  assert.doesNotMatch(packageSection, /For Homes|For Business|typical Filipino homes|businesses/i);
  assert.doesNotMatch(`${packageSection}\n${navSection}`, /Get My\s+.*FREE\s+Quote|Get a free quote|Talk to an Engineer|Book a free assessment/);
  assert.doesNotMatch(packageSection, /25-Yr Warranty|25-Year Warranty|25-Year Lifespan/);
  assert.match(packageSection, /25-Yr Panel Warranty/);
  assert.doesNotMatch(html, /PKG\.find\s*\(/);

  assert.match(navSection, /<button class="nav-toggle"/);
  assert.match(navSection, /aria-expanded="false"/);
  assert.match(navSection, /aria-controls="navMobile"/);
  assert.match(navSection, /aria-label="Open navigation menu"/);
  assert.match(html, /NAV_REVAMP_START[\s\S]*Escape[\s\S]*resize/);
  assert.match(html, /!panel\.contains\(event\.target\)/);
  assert.match(html, /function setOpen\(open, returnFocus\)/);
  assert.match(html, /toggle\.addEventListener\('click'/);
  assert.match(html, /Array\.prototype\.forEach\.call\(focusable/);
  assert.match(html, /event\.key === 'Escape'/);
  assert.match(html, /window\.innerWidth > 960/);
}

const css = fs.readFileSync('styles/main.css', 'utf8');
assert.match(css, /\.nav-toggle[\s\S]*width:44px[\s\S]*height:44px/);
assert.match(css, /@media \(max-width:960px\)/);
assert.match(css, /scroll-snap-type:x mandatory/);
assert.match(css, /prefers-reduced-motion:reduce/);

console.log('packages_navigation: PASS');
