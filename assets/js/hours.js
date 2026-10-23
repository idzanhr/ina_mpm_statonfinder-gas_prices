// "Is it open right now?" from the ina.hr working-hours rows.
// Shared by the public page (window.INAHours) and the tests (node: require()).
// It never guesses: anything it cannot read is reported as "unknown".
(function (root) {
  "use strict";

  var DAY = 1440; // minutes

  /**
   * One cell of the hours table → { kind: "closed" } | { kind: "ranges", ranges: [[start, end], …] }
   * | null (empty / unreadable). Minutes from midnight; end > 1440 means "past midnight".
   * Understands "06-20", "6:00 - 22:00", "06-14, 15-20", "0-24", "NON-STOP" / "NON - STOP" / "Non – Stop",
   * "24 h", "22-06", "NE RADI".
   */
  function parseSpec(text) {
    var t = String(text || "").toUpperCase().replace(/\s+/g, " ").trim();
    if (!t) return null;
    // Non-stop in any spelling (ina.hr writes e.g. "NON - STOP"); "24 h" only as the whole cell,
    // so "06-24 h" stays an ordinary range.
    if (/NON\s*[-–—]?\s*STOP|24\s*\/\s*7|^24\s*(?:H|SATA)$/.test(t)) return { kind: "ranges", ranges: [[0, DAY]] };
    var re = /(\d{1,2})(?:[:.h](\d{2}))?\s*(?:-|–|—|DO)\s*(\d{1,2})(?:[:.h](\d{2}))?/g, m, ranges = [];
    while ((m = re.exec(t))) {
      var sh = +m[1], sm = +(m[2] || 0), eh = +m[3], em = +(m[4] || 0);
      if (sh > 24 || eh > 24 || sm > 59 || em > 59) continue;
      var start = sh * 60 + sm, end = eh * 60 + em;
      if (end <= start) end += DAY; // e.g. 22-06 runs into the next day
      ranges.push([start, end]);
    }
    if (ranges.length) return { kind: "ranges", ranges: ranges };
    if (/NE ?RADI|ZATVOREN/.test(t)) return { kind: "closed" };
    return null;
  }

  // ---- calendar helpers (dates as {y, m, d}; m = 1–12) -------------------
  function toDays(y, m, d) { return Math.floor(Date.UTC(y, m - 1, d) / 864e5); }
  function fromDays(n) { var x = new Date(n * 864e5); return { y: x.getUTCFullYear(), m: x.getUTCMonth() + 1, d: x.getUTCDate(), dow: x.getUTCDay() }; }

  /** Easter Sunday (Gregorian), as a day number. */
  function easter(y) {
    var a = y % 19, b = Math.floor(y / 100), c = y % 100, d = Math.floor(b / 4), e = b % 4,
      f = Math.floor((b + 8) / 25), g = Math.floor((b - f + 1) / 3), h = (19 * a + b - d - g + 15) % 30,
      i = Math.floor(c / 4), k = c % 4, l = (32 + 2 * e + 2 * i - h - k) % 7, mm = Math.floor((a + 11 * h + 22 * l) / 451),
      month = Math.floor((h + l - 7 * mm + 114) / 31), day = ((h + l - 7 * mm + 114) % 31) + 1;
    return toDays(y, month, day);
  }

  /** Croatian public holidays (Zakon o blagdanima, since 2020). */
  function isPublicHoliday(dayNum) {
    var x = fromDays(dayNum), md = x.m * 100 + x.d;
    if ([101, 106, 501, 530, 622, 805, 815, 1101, 1118, 1225, 1226].indexOf(md) !== -1) return true;
    var e = easter(x.y);
    return dayNum === e || dayNum === e + 1 || dayNum === e + 60; // Easter, Easter Monday, Corpus Christi
  }

  /**
   * The "Blagdani" cell: either dated entries ("25.12.2024. NE RADI 26.12.2024. 06-20")
   * or one generic value for all public holidays ("07-13").
   */
  function parseHolidays(text) {
    var t = String(text || ""), re = /(\d{1,2})\.\s?(\d{1,2})\.\s?(\d{4})?\.?/g, m, marks = [];
    while ((m = re.exec(t))) marks.push({ at: m.index, end: re.lastIndex, d: +m[1], m: +m[2], y: m[3] ? +m[3] : null });
    if (!marks.length) return { dated: [], generic: parseSpec(t) };
    var dated = marks.map(function (k, i) {
      return { d: k.d, m: k.m, y: k.y, spec: parseSpec(t.slice(k.end, i + 1 < marks.length ? marks[i + 1].at : t.length)) };
    });
    return { dated: dated, generic: null };
  }

  /** Row label with a date range ("Sezonsko 01.06.-30.09.") → does it cover this day? null = no range. */
  function labelCovers(label, x) {
    var m = String(label || "").match(/(\d{1,2})\.\s?(\d{1,2})\.?\s?(?:\d{4}\.?)?\s*(?:-|–|DO)\s*(\d{1,2})\.\s?(\d{1,2})\./i);
    if (!m) return null;
    var from = +m[2] * 100 + +m[1], to = +m[4] * 100 + +m[3], md = x.m * 100 + x.d;
    return from <= to ? md >= from && md <= to : md >= from || md <= to;
  }

  /** Which table row applies on a day: one whose dated label covers it, else "Redovno", else the first. */
  function rowFor(rows, x) {
    var covering = rows.filter(function (r) { return labelCovers(r.label, x) === true; });
    if (covering.length) return covering[0];
    var regular = rows.filter(function (r) { return /redovno/i.test(r.label || ""); });
    return regular[0] || rows[0];
  }

  /** The spec for one calendar day, plus whether a public holiday made it uncertain. */
  function specFor(rows, dayNum) {
    var x = fromDays(dayNum), row = rowFor(rows, x);
    if (!row) return { spec: null };
    var hol = parseHolidays(row.hol);
    for (var i = 0; i < hol.dated.length; i++) {
      var h = hol.dated[i];
      if (h.d === x.d && h.m === x.m && (h.y === null || h.y === x.y)) return { spec: h.spec, holiday: true };
    }
    var normal = parseSpec(x.dow === 0 ? row.sun : x.dow === 6 ? row.sat : row.week);
    if (isPublicHoliday(dayNum)) {
      if (hol.generic) return { spec: hol.generic, holiday: true };
      // Holiday, but INA lists no holiday hours: flag it – except for non-stop days, which stay non-stop.
      var allDay = normal && normal.kind === "ranges" && normal.ranges.some(function (r) { return r[0] === 0 && r[1] >= DAY; });
      return { spec: normal, holiday: true, unsure: !allDay };
    }
    return { spec: normal };
  }

  /**
   * State at a moment: now = { day: dayNumber, min: minutes since midnight } in Zagreb time.
   * → { state: "open"|"closed"|"unknown", nonstop, until, opens, unsure }
   *   until / opens = { day, min } of the next change (null when not within a week).
   */
  function status(rows, now) {
    if (!rows || !rows.length) return { state: "unknown", reason: "none" };
    var today = specFor(rows, now.day);
    if (!today.spec) return { state: "unknown", reason: "unreadable" };
    // Open intervals in absolute minutes from yesterday to a week ahead.
    var iv = [], unknownAhead = null;
    for (var off = -1; off <= 7; off++) {
      var s = specFor(rows, now.day + off);
      if (!s.spec) { if (off > 0 && unknownAhead === null) unknownAhead = off; continue; }
      if (s.spec.kind !== "ranges") continue;
      s.spec.ranges.forEach(function (r) { iv.push([(now.day + off) * DAY + r[0], (now.day + off) * DAY + r[1]]); });
    }
    iv.sort(function (a, b) { return a[0] - b[0]; });
    var merged = [];
    iv.forEach(function (r) {
      var last = merged[merged.length - 1];
      if (last && r[0] <= last[1]) last[1] = Math.max(last[1], r[1]); else merged.push([r[0], r[1]]);
    });
    var t = now.day * DAY + now.min, horizon = (now.day + 7) * DAY;
    function at(abs) { return abs >= horizon ? null : { day: Math.floor(abs / DAY), min: abs % DAY }; }
    for (var j = 0; j < merged.length; j++) {
      if (merged[j][0] <= t && t < merged[j][1]) {
        var nonstop = merged[j][0] <= t - DAY && merged[j][1] >= horizon;
        return { state: "open", nonstop: nonstop, until: nonstop ? null : at(merged[j][1]), unsure: !!today.unsure };
      }
      if (merged[j][0] > t) return { state: "closed", opens: at(merged[j][0]), unsure: !!today.unsure };
    }
    return { state: "closed", opens: null, unsure: !!today.unsure || unknownAhead !== null };
  }

  /** Current day number + minutes in Europe/Zagreb. */
  function zagrebNow(date) {
    var parts = {};
    try {
      new Intl.DateTimeFormat("en-GB", { timeZone: "Europe/Zagreb", year: "numeric", month: "2-digit", day: "2-digit", hour: "2-digit", minute: "2-digit", hourCycle: "h23" })
        .formatToParts(date || new Date()).forEach(function (p) { parts[p.type] = +p.value; });
    } catch (e) {
      var d = date || new Date();
      parts = { year: d.getFullYear(), month: d.getMonth() + 1, day: d.getDate(), hour: d.getHours(), minute: d.getMinutes() };
    }
    return { day: toDays(parts.year, parts.month, parts.day), min: parts.hour * 60 + parts.minute };
  }

  var DAYS = ["ned", "pon", "uto", "sri", "čet", "pet", "sub"];
  function hhmm(min) { return String(Math.floor(min / 60)).padStart(2, "0") + ":" + String(min % 60).padStart(2, "0"); }
  function when(at, now) {
    if (!at) return "";
    if (at.day === now.day) return hhmm(at.min);
    if (at.day === now.day + 1) return at.min === 0 ? "ponoći" : "sutra " + hhmm(at.min);
    return DAYS[fromDays(at.day).dow] + " " + hhmm(at.min);
  }

  /** Short Croatian text for the bubble. */
  function label(st, now) {
    if (st.state === "unknown") return st.reason === "none" ? "Radno vrijeme nije upisano" : "Provjerite radno vrijeme";
    if (st.state === "open") {
      if (st.nonstop) return "Non-stop · sada radi";
      return "Sada radi" + (st.until ? " · do " + when(st.until, now) : "");
    }
    if (!st.opens) return "Sada ne radi";
    var w = when(st.opens, now);
    return "Sada ne radi · otvara " + (st.opens.day === now.day ? "u " + w : w.indexOf("sutra") === 0 ? w.replace("sutra ", "sutra u ") : "u " + w);
  }

  var api = { parseSpec: parseSpec, parseHolidays: parseHolidays, isPublicHoliday: isPublicHoliday, easter: easter,
    toDays: toDays, fromDays: fromDays, status: status, zagrebNow: zagrebNow, label: label };
  if (typeof module === "object" && module.exports) module.exports = api; else root.INAHours = api;
})(this);
