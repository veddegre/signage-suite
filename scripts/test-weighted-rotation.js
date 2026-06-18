#!/usr/bin/env node
/**
 * Sanity checks for board.php rotation logic (weighted pick + hour windows).
 * Run: node scripts/test-weighted-rotation.js
 */

function pageWeight(p) {
  const w = parseInt(p.weight, 10);
  return (!isNaN(w) && w > 0) ? Math.min(20, w) : 1;
}

function inWindowAt(p, hour) {
  if (p.from == null || p.to == null || p.from === '' || p.to === '') return true;
  const a = +p.from;
  const b = +p.to;
  return a <= b ? (hour >= a && hour < b) : (hour >= a || hour < b);
}

function pickWeightedPage(pages, excludeIdx, inWindow) {
  const eligible = [];
  for (let i = 0; i < pages.length; i++) {
    if (inWindow(pages[i])) eligible.push(i);
  }
  if (eligible.length === 0) return excludeIdx >= 0 ? excludeIdx : 0;
  let total = 0;
  const pool = [];
  for (const i of eligible) {
    const w = pageWeight(pages[i]);
    pool.push({ i, w });
    total += w;
  }
  let r = Math.random() * total;
  for (const item of pool) {
    r -= item.w;
    if (r <= 0) return item.i;
  }
  return pool[pool.length - 1].i;
}

// ── Hour window parity (matches rotation_lib.php rotation_page_in_window) ─────
const windowPages = [
  { url: 'day', from: 7, to: 22 },
  { url: 'night', from: 22, to: 7 },
  { url: 'always' },
];
const windowExpect = {
  6: ['night', 'always'],
  7: ['day', 'always'],
  12: ['day', 'always'],
  22: ['night', 'always'],
  23: ['night', 'always'],
};
for (const [hour, expect] of Object.entries(windowExpect)) {
  const got = windowPages.filter((p) => inWindowAt(p, +hour)).map((p) => p.url);
  const ok = got.length === expect.length && got.every((u, i) => u === expect[i]);
  if (!ok) {
    console.error('FAIL hour window at h=' + hour + ': got ' + got.join(',') + ' expected ' + expect.join(','));
    process.exit(1);
  }
}
console.log('Hour window checks: OK');

// ── Weighted distribution ─────────────────────────────────────────────────────
const pages = [
  { url: 'heavy', weight: 10 },
  { url: 'light-a' },
  { url: 'light-b' },
];
const always = () => true;
const trials = 50000;
const counts = [0, 0, 0];
let idx = 0;
for (let t = 0; t < trials; t++) {
  idx = pickWeightedPage(pages, idx, always);
  counts[idx]++;
}
const heavyPct = ((counts[0] / trials) * 100).toFixed(1);
const lightPct = (((counts[1] + counts[2]) / trials) * 100).toFixed(1);
console.log('Weighted rotation simulation (' + trials + ' picks, weights 10:1:1):');
console.log('  heavy:   ' + counts[0] + ' (' + heavyPct + '%, expected ~83%)');
console.log('  light-a: ' + counts[1]);
console.log('  light-b: ' + counts[2]);
console.log('  light total: ' + lightPct + '%');

if (counts[0] / trials < 0.75) {
  console.error('FAIL: heavy page under-represented');
  process.exit(1);
}
console.log('Weighted checks: OK');
