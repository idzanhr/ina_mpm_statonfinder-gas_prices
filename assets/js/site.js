// Public site behaviour: light/dark toggle, storage notice, station search/filter/sort.
// Station cards are pre-rendered into index.html by the CMS; this only shows, hides and orders them.
(function () {
  "use strict";
  var SCHEME_KEY = "ina-postaje-color-scheme";
  var CONSENT_KEY = "ina-postaje-consent";

  function store(key, value) {
    try { value === undefined ? localStorage.removeItem(key) : localStorage.setItem(key, value); } catch (e) { /* blocked */ }
  }
  function read(key) {
    try { return localStorage.getItem(key); } catch (e) { return null; }
  }

  // ---- light/dark: two-state toggle over a three-state setting (system / light / dark).
  // The <meta name="color-scheme"> is the single source of truth; CSS maps it to color-scheme.
  var meta = document.querySelector('meta[name="color-scheme"]');
  var systemDark = window.matchMedia ? matchMedia("(prefers-color-scheme: dark)") : { matches: false };
  function rendered() {
    var c = meta ? meta.content : "light dark";
    return c === "dark" || (c !== "light" && systemDark.matches) ? "dark" : "light";
  }
  function applyStored() {
    var t = read(SCHEME_KEY);
    if (meta) meta.content = t === "light" || t === "dark" ? t : "light dark";
  }
  document.querySelectorAll("[data-theme-toggle]").forEach(function (b) {
    b.addEventListener("click", function () {
      var target = rendered() === "dark" ? "light" : "dark";
      var system = systemDark.matches ? "dark" : "light";
      // Back to what the system shows = undo the override (never pin a value that matches the system).
      store(SCHEME_KEY, target === system ? undefined : target);
      if (meta) meta.content = target === system ? "light dark" : target; // switches even if storage is blocked
    });
  });
  window.addEventListener("storage", function (e) { if (e.key === SCHEME_KEY) applyStored(); });

  // ---- storage notice
  var banner = document.getElementById("cookie");
  if (banner) {
    if (!read(CONSENT_KEY)) banner.hidden = false;
    banner.querySelectorAll("[data-consent]").forEach(function (b) {
      b.addEventListener("click", function () {
        store(CONSENT_KEY, b.getAttribute("data-consent"));
        banner.hidden = true;
      });
    });
  }

  // ---- station list
  var list = document.getElementById("stations");
  var form = document.querySelector("form.filters");
  if (!list || !form) return;
  form.addEventListener("submit", function (e) { e.preventDefault(); });
  var q = document.getElementById("q");
  var county = document.getElementById("county");
  var fuel = document.getElementById("fuel");
  var service = document.getElementById("service");
  var show = document.getElementById("show");
  var sort = document.getElementById("sort");
  var shown = document.getElementById("shown");
  var empty = document.getElementById("empty");
  var note = document.getElementById("filter-note");

  function norm(s) {
    return String(s || "").toLowerCase().normalize("NFD").replace(/[̀-ͯ]/g, "").replace(/đ/g, "d");
  }
  // Temporary closures: re-checked against today's date in Zagreb, so a closure starts and ends
  // on the right day even if nothing was re-published since. Same rule as Stations::closureState().
  var today = (function () {
    try { return new Intl.DateTimeFormat("en-CA", { timeZone: "Europe/Zagreb" }).format(new Date()); }
    catch (e) { return new Date().toISOString().slice(0, 10); }
  })();
  function closureState(el) {
    if (el.getAttribute("data-status") !== "closed") return "open";
    var from = el.getAttribute("data-from"), until = el.getAttribute("data-until");
    if (until && today > until) return "open";
    if (from && today < from) return "upcoming";
    return "closed";
  }
  function json(raw, fallback) { try { return raw ? JSON.parse(raw) : fallback; } catch (e) { return fallback; } }

  var cards = Array.prototype.map.call(list.querySelectorAll(".card"), function (el) {
    var state = closureState(el);
    if (el.hasAttribute("data-status")) {
      el.classList.toggle("card-closed", state === "closed");
      el.querySelectorAll("[data-badge]").forEach(function (b) {
        var kind = b.getAttribute("data-badge");
        b.hidden = kind === "note" ? state === "open" : kind !== state;
      });
    }
    var title = el.querySelector(".card-title");
    var lat = parseFloat(el.getAttribute("data-lat")), lng = parseFloat(el.getAttribute("data-lng"));
    return {
      el: el, name: title ? title.textContent : "", county: el.getAttribute("data-county") || "", closed: state === "closed",
      text: norm(el.getAttribute("data-search")), fuels: (el.getAttribute("data-fuels") || "").split(" ").filter(Boolean),
      prices: json(el.getAttribute("data-prices"), {}), services: (el.getAttribute("data-services") || "").split("|").filter(Boolean),
      hours: json(el.getAttribute("data-hours"), []), bubble: el.querySelector("[data-now]"),
      fuelItems: el.querySelectorAll(".fuel"), distanceEl: el.querySelector("[data-distance]"),
      lat: isFinite(lat) ? lat : null, lng: isFinite(lng) ? lng : null, km: null
    };
  });
  var closedNow = cards.filter(function (c) { return c.closed; }).length;
  var closedSummary = document.getElementById("closed-summary");
  if (closedSummary) {
    closedSummary.hidden = closedNow === 0;
    document.getElementById("closed-count").textContent = closedNow;
  }

  // ---- "Open now" bubble: hours.js against the current Zagreb time, refreshed every minute.
  var H = window.INAHours;
  function updateNow() {
    if (!H) return;
    var now = H.zagrebNow();
    cards.forEach(function (card) {
      var bubble = card.bubble;
      if (card.closed) { card.now = "closed"; if (bubble) bubble.hidden = true; return; } // the red closure badge says it
      var st = H.status(card.hours, now);
      card.now = st.state;
      if (!bubble) return;
      bubble.hidden = st.state === "unknown" && st.reason === "none"; // no hours known: nothing to say
      bubble.className = "now now-" + st.state;
      bubble.textContent = H.label(st, now) + (st.unsure ? " (blagdan – provjerite)" : "");
    });
  }
  updateNow();

  // ---- order: Croatian alphabet (Č, Ć, Dž, Đ, Lj, Nj, Š, Ž in their proper places)
  var collator;
  try { collator = new Intl.Collator("hr", { sensitivity: "base", numeric: true }); }
  catch (e) { collator = { compare: function (a, b) { return a < b ? -1 : a > b ? 1 : 0; } }; }
  var sortNote = "";
  function byName(a, b) { return collator.compare(a.name, b.name); }
  function last(v) { return v == null ? Infinity : v; }
  function applyOrder() {
    var mode = sort ? sort.value : "";
    var cat = fuel ? fuel.value : "";
    sortNote = "";
    if (mode === "cijena" && !cat) sortNote = "za redoslijed po cijeni odaberite gorivo";
    var ordered = cards.slice().sort(function (a, b) {
      if (mode === "za") return collator.compare(b.name, a.name);
      if (mode === "zupanija") return collator.compare(a.county || "￿", b.county || "￿") || byName(a, b);
      if (mode === "cijena" && cat) return last(a.prices[cat]) - last(b.prices[cat]) || byName(a, b);
      if (mode === "blizina") return last(a.km) - last(b.km) || byName(a, b);
      return byName(a, b);
    });
    var frag = document.createDocumentFragment();
    ordered.forEach(function (c) { frag.appendChild(c.el); });
    list.appendChild(frag);
  }

  // ---- "Najbliže meni": distance computed in the browser only, the position is never sent anywhere.
  var located = false;
  function km(lat1, lng1, lat2, lng2) {
    var r = Math.PI / 180, dLat = (lat2 - lat1) * r, dLng = (lng2 - lng1) * r;
    var a = Math.sin(dLat / 2) * Math.sin(dLat / 2) + Math.cos(lat1 * r) * Math.cos(lat2 * r) * Math.sin(dLng / 2) * Math.sin(dLng / 2);
    return 12742 * Math.asin(Math.sqrt(a));
  }
  function locate() {
    if (located) { applyOrder(); filter(); return; }
    if (!navigator.geolocation) { sortNote = "preglednik ne podržava lokaciju"; sort.value = ""; applyOrder(); filter(); return; }
    note.hidden = false;
    note.textContent = " · tražim vašu lokaciju…";
    navigator.geolocation.getCurrentPosition(function (pos) {
      located = true;
      cards.forEach(function (c) {
        if (c.lat === null) return;
        c.km = km(pos.coords.latitude, pos.coords.longitude, c.lat, c.lng);
        if (c.distanceEl) { c.distanceEl.hidden = false; c.distanceEl.textContent = "· " + c.km.toFixed(1).replace(".", ",") + " km"; }
      });
      applyOrder(); filter();
    }, function () {
      sort.value = "";
      applyOrder(); filter();
      note.hidden = false;
      note.textContent = " · lokacija nije dostupna (dopuštenje odbijeno ili nedostupno)";
    }, { enableHighAccuracy: false, timeout: 10000, maximumAge: 300000 });
  }

  function filter() {
    var n = norm(q.value.trim());
    var c = county.value, cat = fuel ? fuel.value : "", sv = service ? service.value : "", mode = show ? show.value : "";
    var count = 0, unknown = 0;
    cards.forEach(function (card) {
      var match = (!c || card.county === c) && (!n || card.text.indexOf(n) !== -1)
        && (!cat || card.fuels.indexOf(cat) !== -1) && (!sv || card.services.indexOf(sv) !== -1);
      var ok = match && !(mode === "otvorene" && card.closed) && !(mode === "sada" && card.now !== "open");
      if (match && mode === "sada" && card.now === "unknown") unknown++;
      card.el.hidden = !ok;
      if (ok) count++;
      Array.prototype.forEach.call(card.fuelItems, function (li) { li.classList.toggle("match", !!cat && li.getAttribute("data-cat") === cat); });
    });
    shown.textContent = count;
    empty.hidden = count !== 0;
    var notes = [];
    if (unknown) notes.push("bez poznatog radnog vremena, nisu prikazane: " + unknown);
    if (sortNote) notes.push(sortNote);
    note.hidden = notes.length === 0;
    note.textContent = notes.length ? " · " + notes.join(" · ") : "";

    var params = new URLSearchParams();
    if (q.value.trim()) params.set("q", q.value.trim());
    if (c) params.set("zupanija", c);
    if (cat) params.set("gorivo", cat);
    if (sv) params.set("usluga", sv);
    if (mode) params.set("prikaz", mode);
    if (sort && sort.value && sort.value !== "blizina") params.set("redoslijed", sort.value); // location is never put in a link
    var qs = params.toString();
    history.replaceState(null, "", qs ? "?" + qs : location.pathname);
  }

  // Restore a shared/bookmarked search.
  var initial = new URLSearchParams(location.search);
  function restore(el, key) {
    var v = initial.get(key);
    if (!el || v === null) return;
    el.value = v;
    if (el.value !== v) el.value = ""; // unknown option
  }
  if (initial.get("q")) q.value = initial.get("q");
  restore(county, "zupanija"); restore(fuel, "gorivo"); restore(service, "usluga"); restore(show, "prikaz");
  if (initial.get("redoslijed") !== "blizina") restore(sort, "redoslijed");

  q.addEventListener("input", filter);
  [county, service, show].forEach(function (el) { if (el) el.addEventListener("change", filter); });
  if (fuel) fuel.addEventListener("change", function () { if (sort && sort.value === "cijena") applyOrder(); filter(); });
  if (sort) sort.addEventListener("change", function () {
    if (sort.value === "blizina") return locate();
    applyOrder(); filter();
  });
  if (sort && sort.value) applyOrder();
  if (location.search) filter();

  function refresh() {
    updateNow();
    if (show && show.value === "sada") filter();
  }
  setInterval(refresh, 60000);
  document.addEventListener("visibilitychange", function () { if (!document.hidden) refresh(); });
})();
