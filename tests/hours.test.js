// Dependency-free tests for assets/js/hours.js:  node tests/hours.test.js
"use strict";
const H = require("../assets/js/hours.js");
let count = 0, failures = 0;
function test(name, fn) {
  count++;
  try { fn(); console.log("  ok  " + name); }
  catch (e) { failures++; console.log("  FAIL " + name + "\n       " + e.message); }
}
function eq(expected, actual) {
  const a = JSON.stringify(actual), b = JSON.stringify(expected);
  if (a !== b) throw new Error("expected " + b + ", got " + a);
}
// A moment in Zagreb: date "YYYY-MM-DD", time "HH:MM".
function at(date, time) {
  const [y, m, d] = date.split("-").map(Number), [hh, mm] = time.split(":").map(Number);
  return { day: H.toDays(y, m, d), min: hh * 60 + mm };
}
const regular = [{ label: "Redovno", week: "06-20", sat: "07-14", sun: "NE RADI", hol: "" }];
const state = (rows, date, time) => H.status(rows, at(date, time));
const text = (rows, date, time) => H.label(H.status(rows, at(date, time)), at(date, time));

console.log("Parsing the ina.hr cells");
test("hour formats", () => {
  eq({ kind: "ranges", ranges: [[360, 1200]] }, H.parseSpec("06-20"));
  eq({ kind: "ranges", ranges: [[360, 1320]] }, H.parseSpec("6:00 - 22:00"));
  eq({ kind: "ranges", ranges: [[420, 840], [900, 1200]] }, H.parseSpec("07-14, 15-20"));
  eq({ kind: "ranges", ranges: [[0, 1440]] }, H.parseSpec("0-24"));
  eq({ kind: "ranges", ranges: [[0, 1440]] }, H.parseSpec("00-24"));
  eq({ kind: "ranges", ranges: [[0, 1440]] }, H.parseSpec("NON-STOP"));
  eq({ kind: "ranges", ranges: [[1320, 1800]] }, H.parseSpec("22-06"));
  eq({ kind: "closed" }, H.parseSpec("NE RADI"));
  eq({ kind: "closed" }, H.parseSpec("PRIVREMENO ZATVORENO"));
  eq(null, H.parseSpec(""));
  eq(null, H.parseSpec("prema dogovoru"));
});
test("non-stop in every spelling (Plitvice-Čatrnja: \"NON - STOP\")", () => {
  const all = { kind: "ranges", ranges: [[0, 1440]] };
  for (const t of ["NON - STOP", "NON-STOP", "NONSTOP", "NON STOP", "Non – Stop", "non—stop", "0 - 24", "0-24h", "00:00 - 24:00", "24 h", "24H", "24 SATA", "0-24 SATA", "24/7"]) {
    eq([t, all], [t, H.parseSpec(t)]);
  }
  eq({ kind: "ranges", ranges: [[360, 1440]] }, H.parseSpec("06-24 h")); // not mistaken for non-stop
  const plitvice = [{ label: "Redovno", week: "NON - STOP", sat: "NON - STOP", sun: "NON - STOP", hol: "NON - STOP" }];
  eq("Non-stop · sada radi", text(plitvice, "2026-10-20", "03:15"));
  eq("Non-stop · sada radi", text(plitvice, "2026-12-25", "12:00")); // holiday column says NON - STOP too
});
test("holiday column: dated entries and a generic value", () => {
  const h = H.parseHolidays("25.12.2024. NE RADI 26.12.2024. 06-20");
  eq(2, h.dated.length);
  eq({ d: 25, m: 12, y: 2024, spec: { kind: "closed" } }, h.dated[0]);
  eq({ kind: "ranges", ranges: [[360, 1200]] }, h.dated[1].spec);
  eq({ kind: "ranges", ranges: [[420, 780]] }, H.parseHolidays("07-13").generic);
});
test("Croatian public holidays incl. Easter-based ones", () => {
  eq(H.toDays(2026, 4, 5), H.easter(2026));
  eq(true, H.isPublicHoliday(H.toDays(2026, 4, 6)));   // Easter Monday
  eq(true, H.isPublicHoliday(H.toDays(2026, 6, 4)));   // Corpus Christi (Tijelovo)
  eq(true, H.isPublicHoliday(H.toDays(2026, 11, 18))); // Dan sjećanja na Vukovar
  eq(false, H.isPublicHoliday(H.toDays(2026, 11, 17)));
});

console.log("Open now?");
test("weekday open, closing time shown", () => {
  eq("Sada radi · do 20:00", text(regular, "2026-10-20", "10:00")); // Tuesday
});
test("before opening and after closing", () => {
  eq("Sada ne radi · otvara u 06:00", text(regular, "2026-10-20", "05:30"));
  eq("Sada ne radi · otvara sutra u 06:00", text(regular, "2026-10-20", "20:00")); // closes at 20:00 sharp
});
test("Saturday short day, Sunday closed → opens Monday", () => {
  eq("Sada radi · do 14:00", text(regular, "2026-10-24", "13:59"));
  eq("Sada ne radi · otvara u pon 06:00", text(regular, "2026-10-24", "15:00"));
  eq("Sada ne radi · otvara sutra u 06:00", text(regular, "2026-10-25", "12:00"));
});
test("non-stop stations", () => {
  const ns = [{ label: "Redovno", week: "0-24", sat: "0-24", sun: "0-24", hol: "" }];
  const st = state(ns, "2026-10-20", "03:00");
  eq(["open", true], [st.state, st.nonstop]);
  eq("Non-stop · sada radi", text(ns, "2026-10-20", "03:00"));
});
test("0-24 on weekdays only is open, but not 'non-stop'", () => {
  const wk = [{ label: "Redovno", week: "0-24", sat: "06-22", sun: "NE RADI", hol: "" }];
  eq("Sada radi · do ponoći", text(wk, "2026-10-23", "23:00")); // Friday → Saturday opens 06:00
  eq("Sada radi · do 22:00", text(wk, "2026-10-24", "21:00"));
});
test("overnight hours carry into the next morning", () => {
  const night = [{ label: "Redovno", week: "06-02", sat: "06-02", sun: "06-02", hol: "" }];
  eq("Sada radi · do 02:00", text(night, "2026-10-21", "01:00"));
  eq("Sada ne radi · otvara u 06:00", text(night, "2026-10-21", "03:00"));
});
test("dated holiday entries override the weekday", () => {
  const xmas = [{ label: "Redovno", week: "06-20", sat: "06-20", sun: "06-20", hol: "25.12.2026. NE RADI 26.12.2026. 08-14" }];
  eq("Sada ne radi · otvara sutra u 08:00", text(xmas, "2026-12-25", "10:00"));
  eq("Sada radi · do 14:00", text(xmas, "2026-12-26", "10:00"));
});
test("public holiday without holiday hours is flagged as unsure", () => {
  const st = state(regular, "2026-11-18", "10:00"); // Wednesday, Dan sjećanja na Vukovar
  eq(["open", true], [st.state, st.unsure]);
  eq(false, state(regular, "2026-11-17", "10:00").unsure);
});
test("non-stop stations stay non-stop on holidays without a holiday entry", () => {
  const ns = [{ label: "Redovno", week: "0-24", sat: "0-24", sun: "0-24", hol: "" }];
  const st = state(ns, "2026-12-25", "10:00");
  eq(["open", true, false], [st.state, st.nonstop, st.unsure]);
  const dated = [{ label: "Redovno", week: "0-24", sat: "0-24", sun: "0-24", hol: "25.12.2026. 07-13" }];
  eq("Sada radi · do 13:00", text(dated, "2026-12-25", "10:00")); // an explicit holiday entry still wins
});
test("generic holiday hours apply on public holidays", () => {
  const g = [{ label: "Redovno", week: "06-20", sat: "06-20", sun: "NE RADI", hol: "07-13" }];
  eq("Sada ne radi · otvara sutra u 06:00", text(g, "2026-11-18", "14:00"));
});
test("seasonal row applies inside its date range", () => {
  const rows = [
    { label: "Redovno", week: "06-20", sat: "06-20", sun: "06-20", hol: "" },
    { label: "Sezonsko 01.06.-30.09.", week: "06-23", sat: "06-23", sun: "06-23", hol: "" },
  ];
  eq("Sada radi · do 23:00", text(rows, "2026-07-15", "21:00"));
  eq("Sada ne radi · otvara sutra u 06:00", text(rows, "2026-10-20", "21:00"));
});
test("PRIVREMENO ZATVORENO everywhere → closed, no opening within a week", () => {
  const z = [{ label: "Redovno", week: "PRIVREMENO ZATVORENO", sat: "PRIVREMENO ZATVORENO", sun: "PRIVREMENO ZATVORENO", hol: "PRIVREMENO ZATVORENO" }];
  eq("Sada ne radi", text(z, "2026-10-20", "10:00"));
});
test("never guesses: no hours or unreadable hours are 'unknown'", () => {
  eq("unknown", state([], "2026-10-20", "10:00").state);
  eq("Radno vrijeme nije upisano", text([], "2026-10-20", "10:00"));
  eq("Provjerite radno vrijeme", text([{ label: "Redovno", week: "po dogovoru", sat: "", sun: "", hol: "" }], "2026-10-20", "10:00"));
});
test("Zagreb clock (CET/CEST) from a UTC instant", () => {
  eq(at("2026-07-01", "02:30"), H.zagrebNow(new Date("2026-07-01T00:30:00Z"))); // summer, UTC+2
  eq(at("2026-12-01", "01:30"), H.zagrebNow(new Date("2026-12-01T00:30:00Z"))); // winter, UTC+1
});

console.log("\n" + count + " tests, " + failures + " failed");
process.exit(failures ? 1 : 0);
