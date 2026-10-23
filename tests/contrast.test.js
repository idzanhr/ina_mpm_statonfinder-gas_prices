// WCAG 2.2 AA contrast check of the light-dark() colour tokens in assets/css/site.css:
//   node tests/contrast.test.js
// Text needs 4.5:1 (1.4.3); field borders, the focus ring and accents need 3:1 (1.4.11).
"use strict";
const fs = require("fs");
const css = fs.readFileSync(__dirname + "/../assets/css/site.css", "utf8");

// --name: light-dark(<light>, <dark>);  →  { light: { name: "oklch(…)" }, dark: { … } }
function tokens() {
  const root = css.match(/:root\s*\{([^}]*)\}/)[1];
  const out = { light: {}, dark: {} };
  for (const m of root.matchAll(/--([\w-]+):\s*light-dark\((oklch\([^)]*\)),\s*(oklch\([^)]*\))\)/g)) {
    out.light[m[1]] = m[2];
    out.dark[m[1]] = m[3];
  }
  return out;
}

// oklch → sRGB (0–1), as browsers do (Björn Ottosson's OKLab), clamped to the sRGB gamut.
function oklch(str) {
  const [, l, c, h, a] = str.match(/oklch\(\s*([\d.]+)\s+([\d.]+)\s+([\d.]+)\s*(?:\/\s*([\d.]+)%?)?\s*\)/);
  const L = +l, C = +c, H = (+h * Math.PI) / 180, A = a === undefined ? 1 : +a / (str.includes("%") ? 100 : 1);
  const A_ = C * Math.cos(H), B_ = C * Math.sin(H);
  const l_ = (L + 0.3963377774 * A_ + 0.2158037573 * B_) ** 3;
  const m_ = (L - 0.1055613458 * A_ - 0.0638541728 * B_) ** 3;
  const s_ = (L - 0.0894841775 * A_ - 1.291485548 * B_) ** 3;
  const lin = [
    4.0767416621 * l_ - 3.3077115913 * m_ + 0.2309699292 * s_,
    -1.2684380046 * l_ + 2.6097574011 * m_ - 0.3413193965 * s_,
    -0.0041960863 * l_ - 0.7034186147 * m_ + 1.707614701 * s_,
  ].map((v) => Math.min(1, Math.max(0, v)));
  const gamma = lin.map((v) => (v <= 0.0031308 ? 12.92 * v : 1.055 * v ** (1 / 2.4) - 0.055));
  return { rgb: gamma, alpha: A };
}
function over(fg, bg) { // alpha-composite in sRGB, like the browser
  return { rgb: fg.rgb.map((v, i) => v * fg.alpha + bg.rgb[i] * (1 - fg.alpha)), alpha: 1 };
}
function luminance({ rgb }) {
  const [r, g, b] = rgb.map((v) => (v <= 0.04045 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4));
  return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}
function ratio(a, b) {
  const [x, y] = [luminance(a), luminance(b)].sort((p, q) => q - p);
  return (x + 0.05) / (y + 0.05);
}

// [foreground token, background token, minimum, where it is used]
const PAIRS = [
  ["foreground", "background", 4.5, "body text"],
  ["foreground", "card", 4.5, "card text"],
  ["muted-foreground", "background", 4.5, "secondary text on page"],
  ["muted-foreground", "card", 4.5, "addresses, block labels, footer links"],
  ["muted-foreground", "secondary", 4.5, "holiday line inside the hours box"],
  ["accent-text", "background", 4.5, "links"],
  ["accent-text", "card", 4.5, "county label, '+ još N'"],
  ["primary-foreground", "primary", 4.5, "buttons, pill"],
  ["secondary-foreground", "secondary", 4.5, "notice, fuel rows, phone button, hours"],
  ["destructive", "card", 4.5, "delete button (admin)"],
  ["closed-fg", "closed-bg", 4.5, "Privremeno zatvoreno badge, error toast"],
  ["closed-fg", "card", 4.5, "closure note"],
  ["upcoming-fg", "upcoming-bg", 4.5, "announced closure badge, ina.hr notice"],
  ["open-fg", "open-bg", 4.5, "'Sada radi' bubble"],
  ["shut-fg", "shut-bg", 4.5, "'Sada ne radi' bubble"],
  ["unknown-fg", "unknown-bg", 4.5, "'Provjerite radno vrijeme' bubble"],
  ["price-fg", "price-bg", 4.5, "price tiles, highlighted fuel row"],
  ["price-bg", "price-fg", 4.5, "'Nova cijena' flag (inverted)"],
  ["price-accent", "price-bg", 3, "price tile border, fuel highlight (1.4.11)"],
  ["input", "card", 3, "input / select / button border (1.4.11)"],
  ["input", "background", 3, "input border on page (1.4.11)"],
  ["ring", "background", 3, "focus outline (1.4.11)"],
  ["ring", "card", 3, "focus outline on cards (1.4.11)"],
];

const all = tokens();
if (Object.keys(all.light).length < 20) { console.log("FAIL: could not read the light-dark() tokens"); process.exit(1); }
let failures = 0, count = 0;
for (const theme of ["light", "dark"]) {
  const t = all[theme];
  console.log(theme);
  for (const [fg, bg, min, where] of PAIRS) {
    count++;
    if (!t[fg] || !t[bg]) { failures++; console.log(`  FAIL missing token --${fg} or --${bg}`); continue; }
    const back = over(oklch(t[bg]), oklch(t["background"]));
    const r = ratio(over(oklch(t[fg]), back), back);
    const ok = r >= min;
    if (!ok) failures++;
    console.log(`  ${ok ? "ok  " : "FAIL"} ${r.toFixed(2).padStart(5)} ≥ ${min}  --${fg} on --${bg}  (${where})`);
  }
}
console.log(`\n${count} checks, ${failures} failed`);
process.exit(failures ? 1 : 0);
