// Admin editor. Talks to api.php; the server re-validates everything.
(function () {
  "use strict";
  var app = document.getElementById("app");
  var boot = JSON.parse(app.getAttribute("data-boot"));
  var state = { stations: [], q: "", which: "", editing: null };

  // ---- helpers
  function h(tag, attrs) {
    var el = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) {
      var v = attrs[k];
      if (v == null || v === false) return;
      if (k === "class") el.className = v;
      else if (k === "text") el.textContent = v;
      else if (k.slice(0, 2) === "on") el.addEventListener(k.slice(2), v);
      else if (k === "value") el.value = v;
      else el.setAttribute(k, v === true ? "" : v);
    });
    for (var i = 2; i < arguments.length; i++) {
      var c = arguments[i];
      if (c == null || c === false) continue;
      (Array.isArray(c) ? c : [c]).forEach(function (n) { el.append(n instanceof Node ? n : document.createTextNode(String(n))); });
    }
    return el;
  }
  function norm(s) {
    return String(s || "").toLowerCase().normalize("NFD").replace(/[̀-ͯ]/g, "").replace(/đ/g, "d");
  }
  function api(action, body, isForm) {
    var opts = { method: body ? "POST" : "GET", headers: { "X-CSRF-Token": boot.csrf }, credentials: "same-origin" };
    if (body && isForm) opts.body = body;
    else if (body) { opts.headers["Content-Type"] = "application/json"; opts.body = JSON.stringify(body); }
    return fetch(boot.api + "?action=" + encodeURIComponent(action), opts).then(function (r) {
      return r.json().catch(function () { return { error: "Neočekivan odgovor poslužitelja." }; }).then(function (data) {
        if (r.status === 401) { location.reload(); throw new Error(data.error); }
        if (!r.ok) throw new Error(data.error || "Greška " + r.status);
        if (data.csrf) boot.csrf = data.csrf;
        return data;
      });
    });
  }
  var toastEl = h("div", { class: "toast", role: "status", hidden: true });
  document.body.append(toastEl);
  var toastTimer;
  function toast(msg, isError) {
    toastEl.textContent = msg;
    toastEl.className = "toast" + (isError ? " toast-error" : "");
    toastEl.hidden = false;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { toastEl.hidden = true; }, isError ? 7000 : 3500);
  }
  function fail(err) { toast(err.message, true); }
  function input(attrs) { return h("input", Object.assign({ class: "input" }, attrs)); }
  function field(label, control, cls) { return h("label", { class: "field " + (cls || "") }, h("span", { text: label }), control); }
  // Same rule as Stations::closureState() in PHP / site.js.
  var today = (function () {
    try { return new Intl.DateTimeFormat("en-CA", { timeZone: "Europe/Zagreb" }).format(new Date()); }
    catch (e) { return new Date().toISOString().slice(0, 10); }
  })();
  function closureState(s) {
    if (s.status !== "closed") return "open";
    if (s.closed_until && today > s.closed_until) return "expired";
    if (s.closed_from && today < s.closed_from) return "upcoming";
    return "closed";
  }
  function euro(p) { return p ? String(p).replace(".", ",") + " €" : ""; }
  function hrDate(ymd) { var p = (ymd || "").split("-"); return p.length === 3 ? (+p[2]) + ". " + (+p[1]) + ". " + p[0] + "." : ""; }
  function statusBadge(s) {
    var st = closureState(s), until = s.closed_until ? " do " + hrDate(s.closed_until) : "";
    if (st === "closed") return h("span", { class: "card-badge badge-closed", text: "Privremeno zatvoreno" + until + (s.status_source === "ina" ? " · automatski s ina.hr" : "") });
    if (st === "upcoming") return h("span", { class: "card-badge badge-upcoming", text: "Zatvaranje od " + hrDate(s.closed_from) + until });
    if (st === "expired") return h("span", { class: "card-badge badge-upcoming", text: "Zatvaranje isteklo " + hrDate(s.closed_until) + " – provjerite i označite kao otvorenu" });
    return null;
  }

  var qrReady;
  function loadQr() {
    qrReady = qrReady || boot.qrScripts.reduce(function (p, src) {
      return p.then(function () {
        return new Promise(function (resolve, reject) {
          document.head.append(h("script", { src: src, onload: resolve, onerror: function () { reject(new Error("QR skripta se nije učitala.")); } }));
        });
      });
    }, Promise.resolve());
    return qrReady;
  }
  function codesList(codes) { return h("ul", { class: "codes" }, codes.map(function (c) { return h("li", null, h("code", { text: c })); })); }

  // ---- layout
  var tabs = [["stations", "Postaje"], ["prices", "Cijene"], ["data", "Uvoz / izvoz"], ["audit", "Dnevnik"], ["security", "Sigurnost"]];
  var current = "stations";
  try { current = sessionStorage.getItem("ina-tab") || current; } catch (e) { /* ignore */ }
  if (!tabs.some(function (t) { return t[0] === current; })) current = "stations";
  var nav = h("nav", { class: "tabs", role: "tablist" });
  var view = h("section", { class: "tab-view" });
  app.replaceChildren(nav, view);

  function renderTabs() {
    nav.replaceChildren.apply(nav, tabs.map(function (t) {
      return h("button", { type: "button", role: "tab", "aria-selected": String(t[0] === current), class: "tab" + (t[0] === current ? " active" : ""),
        onclick: function () { current = t[0]; try { sessionStorage.setItem("ina-tab", current); } catch (e) { /* ignore */ } show(); } }, t[1]);
    }));
  }
  function show() {
    renderTabs();
    ({ stations: viewStations, prices: viewPrices, data: viewData, audit: viewAudit, security: viewSecurity })[current]();
  }

  // ---- stations
  function load() {
    return api("list").then(function (d) { state.stations = d.stations; });
  }
  // Same sources as Render::cardFuels(): ina.hr fuels, Usluge/Ostalo, or the admin's checkboxes.
  function hasAdblue(s) {
    return s.adblue_have || s.adblue_pump || s.fuels.some(function (f) { return f.cat === "adblue"; })
      || s.services.some(function (x) { return /ad\s*blue/i.test(x); });
  }
  function fuelSummary(s) {
    return (s.fuels || []).map(function (f) { return f.name + (f.price ? " " + euro(f.price) : ""); }).join(" · ");
  }
  var FILTERS = [
    ["", "Sve postaje", function () { return true; }],
    ["closed", "Privremeno zatvorene / najavljene", function (s) { return s.status === "closed"; }],
    ["notice", "Napomena s ina.hr za provjeru", function (s) { return !!s.ina_notice; }],
    ["missing", "Više nisu u tražilici ina.hr", function (s) { return s.ina_missing; }],
    ["hidden", "Sakrivene s javne stranice", function (s) { return s.hidden; }],
    ["locked", "Zaključane (ručno uređene)", function (s) { return s.locked; }],
    ["manual", "Bez INA poveznice", function (s) { return !s.ina_url; }],
    ["nocounty", "Bez županije", function (s) { return !s.county; }],
    ["nohours", "Bez radnog vremena", function (s) { return !s.working_hours.length; }],
    ["nofuels", "Bez goriva / cijena", function (s) { return !s.fuels.length; }],
    ["adblue", "Imaju AdBlue", hasAdblue],
    ["noadblue", "Bez AdBlue", function (s) { return !hasAdblue(s); }],
    ["adbluepump", "AdBlue iz pištolja (označeno)", function (s) { return s.adblue_pump; }]
  ];

  function viewStations() {
    var search = input({ type: "search", placeholder: "Traži postaju, mjesto, nadimak, županiju…", value: state.q,
      oninput: function (e) { state.q = e.target.value; renderList(); } });
    var list = h("div", { class: "rows" });
    var formSlot = h("div");
    var count = h("p", { class: "muted small" });
    var which = h("select", { class: "input toolbar-filter", "aria-label": "Prikaz", onchange: function (e) { state.which = e.target.value; renderList(); } },
      FILTERS.map(function (f) { return h("option", { value: f[0], selected: state.which === f[0] }, f[1]); }));
    var dirBtn = h("button", { class: "btn-ghost", type: "button", title: "Dodaje nove postaje s ina.hr i ažurira adrese i telefone. Radno vrijeme i cijene osvježava cron (bin/sync-ina.php).", onclick: function () {
      dirBtn.disabled = true; dirBtn.textContent = "Učitavam…";
      api("ina-directory", {}).then(function (d) {
        var r = d.result;
        toast(r.directoryAvailable ? "ina.hr: " + r.directory + " postaja · novih " + r.added + " · ažuriranih " + r.details + " · nestalih " + r.missing : "Tražilica ina.hr nije dostupna.", !r.directoryAvailable);
        return load();
      }).then(renderList, fail).then(function () { dirBtn.disabled = false; dirBtn.textContent = "Osvježi popis s ina.hr"; });
    } }, "Osvježi popis s ina.hr");
    view.replaceChildren(
      h("div", { class: "toolbar" }, search, which, dirBtn, h("button", { class: "btn-primary", type: "button", onclick: function () { state.editing = "new"; renderList(); } }, "+ Nova postaja")),
      count, formSlot, list
    );
    function renderList() {
      var n = norm(state.q.trim());
      var test = (FILTERS.filter(function (f) { return f[0] === state.which; })[0] || FILTERS[0])[2];
      var shown = state.stations.filter(function (s) {
        return test(s) && (!n || norm([s.name, s.aliases, s.county, s.ina_region, s.city, s.postal_code, s.address, s.phone, s.status_note, s.note].join(" ")).indexOf(n) !== -1);
      });
      var notices = state.stations.filter(function (s) { return s.ina_notice; }).length;
      var missing = state.stations.filter(function (s) { return s.ina_missing; }).length;
      count.textContent = "Ukupno: " + state.stations.length + " · prikazano: " + shown.length
        + (notices ? " · napomena s ina.hr za provjeru: " + notices : "") + (missing ? " · više nisu na ina.hr: " + missing : "");
      formSlot.replaceChildren(state.editing === "new" ? stationForm(null, done) : "");
      // Hundreds of rows: render the first 200 matches, searching narrows the rest.
      var limit = 200;
      list.replaceChildren.apply(list, shown.slice(0, limit).map(function (s) {
        if (state.editing === s.id) return h("div", { class: "row editing" }, stationForm(s, done));
        var flags = [s.hidden ? "sakrivena" : null, s.locked ? "zaključana" : null, !s.ina_url ? "bez INA poveznice" : null,
          hasAdblue(s) ? "AdBlue" : null, s.adblue_pump ? "AdBlue iz pištolja" : null].filter(Boolean);
        return h("div", { class: "row" + (s.hidden ? " row-hidden" : "") },
          h("div", { class: "row-main" },
            h("p", { class: "row-title" }, s.name, " ", h("span", { class: "muted small", text: "· " + [s.city, s.county || "bez županije", s.ina_region ? "regija " + s.ina_region : ""].filter(Boolean).join(", ") })),
            flags.length ? h("p", { class: "flags small" }, flags.map(function (f) { return h("span", { class: "flag", text: f }); })) : null,
            statusBadge(s),
            s.fuels.length ? h("p", { class: "muted small truncate", title: fuelSummary(s), text: fuelSummary(s) }) : null,
            s.ina_missing ? h("p", { class: "ina-notice", text: "⚠ Postaje više nema u tražilici ina.hr – provjerite, pa je sakrijte ili obrišite." }) : null,
            s.ina_notice ? h("p", { class: "ina-notice", title: "Pronađeno na ina.hr pri automatskoj provjeri. Provjerite i po potrebi označite postaju kao privremeno zatvorenu." },
              "⚠ ina.hr: " + s.ina_notice) : null),
          h("button", { class: "btn-ghost", type: "button", onclick: function () { state.editing = s.id; renderList(); } }, "Uredi"),
          h("button", { class: "btn-danger", type: "button", onclick: function () { del(s); } }, "Obriši"));
      }));
      if (shown.length > limit) list.append(h("p", { class: "muted pad", text: "Prikazano prvih " + limit + " od " + shown.length + " – suzite pretragu." }));
      if (!shown.length) list.append(h("p", { class: "muted pad", text: "Nema rezultata." }));
    }
    function done(saved) {
      state.editing = null;
      if (saved) load().then(renderList, fail); else renderList();
    }
    function del(s) {
      if (!confirm('Obrisati "' + s.name + '"? Promjena se odmah objavljuje.')) return;
      api("delete", { id: s.id }).then(function () { toast("Obrisano i objavljeno."); return load(); }).then(renderList, fail);
    }
    renderList();
  }

  /** Editable list of short texts shown as removable chips (aliases, services). */
  function chipEditor(items, placeholder, emptyText, separator) {
    var chips = h("div", { class: "chips" });
    var add = input({ placeholder: placeholder, onkeydown: function (e) { if (e.key === "Enter") { e.preventDefault(); push(); } } });
    function push() {
      var v = add.value.trim();
      if (separator) v = v.split(separator).join("-");
      if (v && items.indexOf(v) === -1) items.push(v);
      add.value = "";
      render();
      add.focus();
    }
    function render() {
      chips.replaceChildren.apply(chips, items.length ? items.map(function (a, i) {
        return h("span", { class: "chip" }, a, h("button", { type: "button", "aria-label": "Ukloni " + a, onclick: function () { items.splice(i, 1); render(); } }, "×"));
      }) : [h("span", { class: "muted small", text: emptyText })]);
    }
    render();
    return { nodes: [chips, h("div", { class: "inline" }, add, h("button", { type: "button", class: "btn-ghost", onclick: push }, "Dodaj"))], render: render };
  }

  function stationForm(s, onDone) {
    var f = s ? JSON.parse(JSON.stringify(s)) : { name: "", address: "", city: "", postal_code: "", phone: "", county: "", lat: "", lng: "", aliases: "", note: "",
      ina_url: "", working_hours: [], services: [], fuels: [], hidden: 0, locked: 0, adblue_have: 0, adblue_pump: 0, ina_region: "", status: "open", status_note: "", closed_from: "", closed_until: "" };
    f.status = f.status || "open";
    var aliases = f.aliases.split("/").map(function (a) { return a.trim(); }).filter(Boolean);
    var services = f.services.slice();
    function bind(key, attrs) {
      return input(Object.assign({ value: f[key], oninput: function (e) { f[key] = e.target.value; } }, attrs || {}));
    }
    function flag(key, label, help) {
      return h("label", { class: "check" }, h("input", { type: "checkbox", checked: !!f[key], onchange: function (e) { f[key] = e.target.checked ? 1 : 0; } }),
        h("span", null, h("b", { text: label }), " ", h("span", { class: "muted", text: help })));
    }
    var county = h("select", { class: "input", onchange: function (e) { f.county = e.target.value; } },
      h("option", { value: "" }, "— prema poštanskom broju —"),
      boot.counties.map(function (c) { return h("option", { value: c, selected: c === f.county }, c); }));

    var hoursBox = h("div", { class: "hours" });
    function renderHours() {
      hoursBox.replaceChildren.apply(hoursBox, f.working_hours.map(function (row, i) {
        function cell(k, ph) { return input({ value: row[k] || "", placeholder: ph, "aria-label": ph, oninput: function (e) { row[k] = e.target.value; } }); }
        return h("div", { class: "hours-row" }, cell("label", "Naziv (npr. Redovno)"), cell("week", "Pon–Pet, npr. 06-22"), cell("sat", "Subota, npr. 07-14"), cell("sun", "Nedjelja, npr. NE RADI"), cell("hol", "Blagdani, npr. 25.12. NE RADI"),
          h("button", { type: "button", class: "btn-ghost", "aria-label": "Ukloni red", onclick: function () { f.working_hours.splice(i, 1); renderHours(); } }, "×"));
      }).concat([h("button", { type: "button", class: "btn-ghost", onclick: function () { f.working_hours.push({ label: "Redovno", week: "", sat: "", sun: "", hol: "" }); renderHours(); } }, "+ Red radnog vremena")]));
    }
    renderHours();

    var fuelsBox = h("div");
    function renderFuels() {
      fuelsBox.replaceChildren(f.fuels.length
        ? h("table", { class: "table" }, h("tbody", null, f.fuels.map(function (x) {
          return h("tr", null, h("td", { text: x.name }), h("td", { class: "muted", text: boot.fuelCategories[x.cat] || x.cat }),
            h("td", { text: x.cat === "adblue" ? "bez cijene na stranici" : euro(x.price) || "—" }), h("td", { class: "muted", text: x.prev ? "prije " + euro(x.prev) + " (" + (x.changed_at || "").slice(0, 10) + ")" : "" }));
        })))
        : h("p", { class: "muted small", text: "Još nema podataka o gorivima – dolaze sa stranice postaje na ina.hr." }));
    }
    renderFuels();

    // Temporary closure (works on the station, renovation…)
    var closedFields = h("div", { class: "status-fields" },
      field("Napomena (vidljiva na stranici)", bind("status_note", { maxlength: 255, placeholder: "npr. Radovi na postaji" })),
      field("Od (prazno = odmah)", bind("closed_from", { type: "date" })),
      field("Do (prazno = do daljnjeg)", bind("closed_until", { type: "date" })));
    function syncStatus() { closedFields.hidden = f.status !== "closed"; }
    var statusSel = h("select", { class: "input", onchange: function (e) { f.status = e.target.value; syncStatus(); } },
      h("option", { value: "open", selected: f.status !== "closed" }, "Otvorena"),
      h("option", { value: "closed", selected: f.status === "closed" }, "Privremeno zatvorena"));
    var statusBox = h("div", { class: "span2 status-box" },
      h("p", { class: "label", text: "Status postaje" }),
      s && s.ina_notice ? h("p", { class: "ina-notice", text: "⚠ Na ina.hr piše: " + s.ina_notice }) : null,
      s && s.status === "closed" && s.status_source === "ina" ? h("p", { class: "muted small", text: "Zatvoreno automatski prema tablici radnog vremena na ina.hr – ponovno će se otvoriti samo kad ina.hr opet prikaže radno vrijeme. Ako upišete datume, zatvaranje postaje vaše i sinkronizacija ga više ne mijenja." }) : null,
      statusSel, closedFields,
      h("p", { class: "muted small", text: "Zatvorena postaja ostaje na popisu s oznakom, a posjetitelji je mogu sakriti filtrom „Bez privremeno zatvorenih”. Nakon datuma „do” oznaka sama nestaje." }));
    syncStatus();

    var syncBtn = s && h("button", { type: "button", class: "btn-ghost", onclick: function () {
      syncBtn.disabled = true; syncBtn.textContent = "Dohvaćam…";
      api("ina-sync", { id: s.id }).then(function (d) {
        var ok = d.result.updated + d.result.unchanged > 0;
        toast((ok ? "ina.hr: " : "Nije uspjelo – ") + (d.log.join(" ") || "nema odgovora"), !ok);
        if (d.station) {
          ["working_hours", "fuels", "services", "ina_url", "phone", "lat", "lng"].forEach(function (k) { f[k] = d.station[k]; });
          services.splice.apply(services, [0, services.length].concat(d.station.services));
          form.querySelectorAll("[data-key]").forEach(function (el) { el.value = f[el.getAttribute("data-key")] || ""; });
          renderHours(); renderFuels(); servicesEditor.render();
        }
      }, fail).then(function () { syncBtn.disabled = false; syncBtn.textContent = "Osvježi s ina.hr"; });
    } }, "Osvježi s ina.hr");

    var aliasEditor = chipEditor(aliases, "Novi nadimak…", "Nema nadimaka", "/");
    var servicesEditor = chipEditor(services, "Nova usluga, npr. Autopraonica", "Nema upisanih usluga");

    var saveBtn = h("button", { class: "btn-primary", type: "submit" }, "Spremi i objavi");
    var keyed = function (key, attrs) { var el = bind(key, attrs); el.setAttribute("data-key", key); return el; };
    var form = h("form", { class: "station-form", onsubmit: function (e) {
      e.preventDefault();
      if (!f.name.trim()) return toast("Naziv je obavezan.", true);
      f.aliases = aliases.join(" / ");
      f.services = services;
      saveBtn.disabled = true;
      api("save", f).then(function () { toast("Spremljeno i objavljeno."); onDone(true); }, function (err) { saveBtn.disabled = false; fail(err); });
    } },
      field("Naziv *", bind("name", { required: true, maxlength: 190 })),
      field("Adresa", bind("address")),
      field("Grad / naselje", bind("city")),
      field("Poštanski broj", bind("postal_code", { inputmode: "numeric" })),
      field("Telefon", keyed("phone", { type: "tel" })),
      field("Županija", county),
      field("INA regija (organizacijska jedinica, s ina.hr)", bind("ina_region", { maxlength: 60, placeholder: "npr. ZAPAD" })),
      field("Geografska širina (lat)", keyed("lat", { inputmode: "decimal", placeholder: "npr. 45.815011" })),
      field("Geografska dužina (lng)", keyed("lng", { inputmode: "decimal", placeholder: "npr. 15.981919" })),
      field("INA.hr poveznica", keyed("ina_url", { type: "url", placeholder: "https://www.ina.hr/station/…/" }), "span2"),
      field("Napomena na kartici (javno)", bind("note", { maxlength: 500, placeholder: "npr. Ulaz samo iz smjera Splita" }), "span2"),
      h("div", { class: "span2 status-box" }, h("p", { class: "label", text: "Objava i sinkronizacija" }),
        flag("hidden", "Sakrij s javne stranice", "– postaja ostaje u bazi i dalje se sinkronizira."),
        flag("locked", "Zaključaj ručne izmjene", "– sinkronizacija s ina.hr više ne mijenja naziv, adresu, telefon, radno vrijeme i usluge (goriva i cijene i dalje dolaze s ina.hr).")),
      statusBox,
      h("div", { class: "span2" }, h("p", { class: "label", text: "Kako znaju zvati postaju (pretraga)" }), aliasEditor.nodes),
      h("div", { class: "span2" }, h("p", { class: "label", text: "Usluge" }), servicesEditor.nodes,
        h("p", { class: "muted small", text: "Popunjava se s ina.hr; ručne izmjene zadržite opcijom „Zaključaj ručne izmjene”." })),
      h("div", { class: "span2" }, h("p", { class: "label", text: "Goriva i cijene (samo s ina.hr)" }), fuelsBox,
        h("div", { class: "status-box adblue-box" },
          flag("adblue_have", "Ima AdBlue", "– prikazuje AdBlue (bez cijene) i kad ga ina.hr ne navodi. Sinkronizacija ga ne mijenja."),
          flag("adblue_pump", "Moguće istakanje AdBlue iz pištolja", "– provjereno u aplikaciji INA Loyalty (klik na postaju). Na ina.hr se ne vidi, pa ga sinkronizacija nikad ne mijenja. Na stranici se prikazuje uz AdBlue (bez cijene) i u filteru „AdBlue – istakanje iz pištolja”."))),
      h("div", { class: "span2" }, h("p", { class: "label", text: "Radno vrijeme" }),
        s ? h("div", { class: "inline" }, syncBtn, h("span", { class: "muted small", text: "Odmah preuzima radno vrijeme, goriva, cijene i usluge ove postaje s ina.hr i objavljuje." })) : null,
        h("p", { class: "muted small", text: "Za oznaku „Sada radi” upisujte kao na ina.hr: 06-22, 0-24 (non-stop), 22-06 (preko ponoći), NE RADI; blagdani s datumom: 25.12. NE RADI 26.12. 08-14." }), hoursBox),
      h("div", { class: "span2 inline" }, saveBtn, h("button", { type: "button", class: "btn-ghost", onclick: function () { onDone(false); } }, "Odustani"))
    );
    // Focus the first field once the form is in the page, unless the user is already typing in it.
    setTimeout(function () { if (!form.contains(document.activeElement)) form.querySelector("input").focus(); });
    return form;
  }

  // ---- prices: what ina.hr shows now and the recent changes (read-only, official prices only)
  function viewPrices() {
    view.replaceChildren(h("p", { class: "muted", text: "Učitavanje…" }));
    api("prices").then(function (d) {
      var byCat = {};
      state.stations.forEach(function (s) {
        if (s.hidden) return;
        s.fuels.forEach(function (x) {
          if (!x.price || x.cat === "adblue") return; // AdBlue is never priced on the site
          var c = byCat[x.cat] = byCat[x.cat] || {};
          c[x.price] = (c[x.price] || 0) + 1;
        });
      });
      var rows = Object.keys(boot.fuelCategories).filter(function (k) { return byCat[k]; }).map(function (k) {
        var prices = Object.keys(byCat[k]).sort();
        return h("tr", null, h("td", { text: boot.fuelCategories[k] }),
          h("td", { text: prices.map(function (p) { return euro(p) + " (" + byCat[k][p] + ")"; }).join(" · ") }));
      });
      view.replaceChildren(
        h("div", { class: "panel table-wrap" }, h("h2", { class: "pad", text: "Trenutne cijene na ina.hr (cijena: broj postaja)" }),
          rows.length ? h("table", { class: "table" }, h("tbody", null, rows))
            : h("p", { class: "pad muted", text: "Još nema cijena – pokrenite sinkronizaciju: php bin/sync-ina.php --all" }),
          h("p", { class: "pad muted small", text: "Sinkronizacija čita „Goriva u ponudi” sa stranice svake postaje. Promjenu stranica prikazuje kao „Nova cijena” tjedan dana. Ručni unos cijena nije moguć – prikazuju se samo cijene objavljene na ina.hr." })),
        h("div", { class: "panel table-wrap" }, h("h2", { class: "pad", text: "Zadnje promjene cijena (ina.hr)" }), h("table", { class: "table" },
          h("thead", null, h("tr", null, ["Vrijeme", "Postaja", "Gorivo", "Promjena"].map(function (t) { return h("th", { text: t }); }))),
          h("tbody", null, d.changes.length ? d.changes.map(function (c) {
            return h("tr", null, h("td", { text: c.changed_at }), h("td", { text: c.station_name || "(obrisana)" }), h("td", { text: c.fuel_name }),
              h("td", { text: euro(c.old_price) + " → " + euro(c.new_price) }));
          }) : h("tr", null, h("td", { colspan: 4, class: "muted", text: "Još nema zabilježenih promjena." }))))));
    }, fail);
  }

  // ---- import / export
  function viewData() {
    var file = h("input", { type: "file", accept: ".json,.csv,application/json,text/csv", class: "input" });
    var result = h("div");
    var btn = h("button", { class: "btn-primary", type: "submit" }, "Uvezi");
    var addNew = h("input", { type: "checkbox" });
    view.replaceChildren(
      h("div", { class: "panel" },
        h("h2", { text: "Izvoz" }),
        h("p", { class: "muted small", text: "Sigurnosna kopija cijelog popisa." }),
        h("div", { class: "inline" },
          h("a", { class: "btn-ghost", href: boot.api + "?action=export&format=json" }, "Preuzmi JSON"),
          h("a", { class: "btn-ghost", href: boot.api + "?action=export&format=csv" }, "Preuzmi CSV (Excel)"))),
      h("form", { class: "panel", onsubmit: function (e) {
        e.preventDefault();
        if (!file.files[0]) return toast("Odaberite datoteku.", true);
        if (!confirm(addNew.checked ? "Uvoz ažurira postojeće postaje i dodaje nove. Nastaviti?" : "Uvoz ažurira postojeće postaje (nove se ne dodaju). Nastaviti?")) return;
        var fd = new FormData();
        fd.append("file", file.files[0]);
        fd.append("add_new", addNew.checked ? "1" : "0");
        btn.disabled = true;
        api("import", fd, true).then(function (d) {
          btn.disabled = false;
          result.replaceChildren(h("p", { class: "flash flash-info", text: "Novih: " + d.created + " · ažuriranih: " + d.updated + " · grešaka: " + d.errors.length }),
            d.errors.length ? h("ul", { class: "muted small" }, d.errors.map(function (x) { return h("li", { text: x }); })) : "");
          return load();
        }).catch(function (err) { btn.disabled = false; fail(err); });
      } },
        h("h2", { text: "Uvoz" }),
        h("p", { class: "muted small", text: "JSON (izvoz ovog sustava) ili CSV sa zaglavljem kao u izvozu (name;address;city;postal_code;county;ina_region;phone;lat;lng;aliases;note;ina_url;hidden;locked;adblue_have;adblue_pump;…). Može i samo dio stupaca, npr. name;address;city;county;adblue_have. Postaja se traži po INA poveznici, zatim po nazivu i mjestu, zatim samo po nazivu (velika/mala slova i č/ć/đ/š/ž se ne razlikuju). Polja kojih nema u datoteci ostaju nepromijenjena; adrese, mjesta i poštanski brojevi iz uvoza ostaju i nakon sinkronizacije. Goriva i cijene se ne uvoze." }),
        file, h("label", { class: "check" }, addNew, h("span", null, h("b", { text: "Dodaj i nove postaje" }), " ",
          h("span", { class: "muted", text: "– inače se retci koje nije moguće povezati s postojećom postajom samo navedu (postaje dolaze s ina.hr)." }))),
        h("div", { class: "inline" }, btn), result),
      h("div", { class: "panel" },
        h("h2", { text: "Objava" }),
        h("p", { class: "muted small", text: "Svaka promjena automatski ponovno generira javnu stranicu (index.html i data/stations.json). Ovdje to možete pokrenuti i ručno, npr. nakon izmjene predloška." }),
        h("button", { class: "btn-ghost", type: "button", onclick: function () { api("publish", {}).then(function () { toast("Javna stranica je ponovno generirana."); }, fail); } }, "Ponovno objavi"))
    );
  }

  // ---- audit log
  var ACTIONS = { create: "Dodano", update: "Izmijenjeno", "delete": "Obrisano", "import": "Uvoz", publish: "Objava", password: "Promjena lozinke",
    "recovery-codes": "Novi rezervni kodovi", price: "Promjena cijene (ina.hr)", "bunny-purge": "Bunny: čišćenje cachea", "totp-rotate": "Nova 2FA aplikacija",
    "ina-sync": "Sinkronizacija s ina.hr", "ina-add": "ina.hr: nova postaja", "ina-missing": "ina.hr: postaje više nema u tražilici", "ina-returned": "ina.hr: postaja ponovno u tražilici",
    "ina-notice": "ina.hr: napomena o zatvaranju", "ina-close": "ina.hr: automatski zatvoreno", "ina-reopen": "ina.hr: automatski ponovno otvoreno", "totp-reset": "2FA resetirana (CLI)" };
  function viewAudit() {
    view.replaceChildren(h("p", { class: "muted", text: "Učitavanje…" }));
    api("audit").then(function (d) {
      view.replaceChildren(h("div", { class: "panel table-wrap" }, h("table", { class: "table" },
        h("thead", null, h("tr", null, ["Vrijeme", "Radnja", "Postaja", "Detalji", "IP"].map(function (t) { return h("th", { text: t }); }))),
        h("tbody", null, d.entries.length ? d.entries.map(function (e) {
          return h("tr", null, h("td", { text: e.created_at }), h("td", { text: ACTIONS[e.action] || e.action }), h("td", { text: e.station_name }), h("td", { text: e.detail }), h("td", { text: e.ip }));
        }) : h("tr", null, h("td", { colspan: 5, class: "muted", text: "Još nema zapisa." }))))));
    }, fail);
  }

  // ---- security
  function viewSecurity() {
    var pw = { current: input({ type: "password", autocomplete: "current-password", required: true }), "new": input({ type: "password", autocomplete: "new-password", minlength: 12, required: true }),
      repeat: input({ type: "password", autocomplete: "new-password", minlength: 12, required: true }), code: input({ inputmode: "numeric", autocomplete: "one-time-code", maxlength: 6, required: true }) };
    var rc = { code: input({ inputmode: "numeric", autocomplete: "one-time-code", maxlength: 6, required: true }), out: h("div") };
    var rot = { current: input({ type: "password", autocomplete: "current-password", required: true }), code: input({ inputmode: "numeric", maxlength: 6, required: true }), out: h("div") };

    view.replaceChildren(
      h("div", { class: "panel" },
        h("h2", { text: "Dvostruka provjera (2FA)" }),
        h("p", { class: "muted small", text: "Obavezna i ne može se isključiti. Prijavljeni ste kao " + boot.email + ". Neiskorištenih rezervnih kodova: " + boot.recoveryLeft + "." })),
      h("form", { class: "panel", onsubmit: function (e) {
        e.preventDefault();
        api("change-password", { current: pw.current.value, "new": pw["new"].value, repeat: pw.repeat.value, code: pw.code.value })
          .then(function () { toast("Lozinka je promijenjena. Ostale sesije su odjavljene."); viewSecurity(); }, fail);
      } }, h("h2", { text: "Promjena lozinke" }),
        h("input", { type: "text", name: "username", autocomplete: "username", value: boot.email, hidden: true }),
        h("div", { class: "form-grid" }, field("Trenutna lozinka", pw.current), field("Kod iz aplikacije", pw.code), field("Nova lozinka (min. 12)", pw["new"]), field("Ponovite novu lozinku", pw.repeat)),
        h("button", { class: "btn-primary", type: "submit" }, "Promijeni lozinku")),
      h("form", { class: "panel", onsubmit: function (e) {
        e.preventDefault();
        api("recovery-codes", { code: rc.code.value }).then(function (d) {
          boot.recoveryLeft = d.codes.length;
          rc.out.replaceChildren(h("p", { class: "flash flash-info", text: "Novi rezervni kodovi (stari više ne vrijede). Spremite ih odmah:" }), codesList(d.codes));
        }, fail);
      } }, h("h2", { text: "Rezervni kodovi" }),
        h("p", { class: "muted small", text: "Jednokratni kodovi za prijavu ako izgubite mobitel. Generiranje novih poništava stare." }),
        h("div", { class: "inline" }, field("Kod iz aplikacije", rc.code), h("button", { class: "btn-ghost", type: "submit" }, "Generiraj nove")), rc.out),
      h("form", { class: "panel", onsubmit: function (e) {
        e.preventDefault();
        api("totp-start", { current: rot.current.value, code: rot.code.value }).then(startRotation, fail);
      } }, h("h2", { text: "Premjesti 2FA na novi uređaj" }),
        h("p", { class: "muted small", text: "Stara aplikacija vrijedi dok ne potvrdite novu." }),
        h("div", { class: "form-grid" }, field("Trenutna lozinka", rot.current), field("Kod iz trenutne aplikacije", rot.code)),
        h("button", { class: "btn-ghost", type: "submit" }, "Nastavi"), rot.out)
    );

    function startRotation(d) {
      var qr = h("div", { class: "qr", role: "img", "aria-label": "QR kod za novu aplikaciju" });
      var code = input({ inputmode: "numeric", autocomplete: "one-time-code", maxlength: 6, required: true });
      rot.out.replaceChildren(h("div", { class: "rotate" },
        h("p", { class: "small", text: "Skenirajte QR kod novom aplikacijom i unesite kod koji prikaže:" }), qr,
        h("details", { class: "small" }, h("summary", { text: "Ručni unos ključa" }), h("code", { class: "secret", text: d.secret })),
        h("div", { class: "inline" }, field("Kod iz nove aplikacije", code), h("button", { class: "btn-primary", type: "button", onclick: function () {
          api("totp-confirm", { code: code.value }).then(function (r) {
            boot.recoveryLeft = r.codes.length;
            rot.out.replaceChildren(h("p", { class: "flash flash-info", text: "Nova aplikacija je aktivna. Novi rezervni kodovi:" }), codesList(r.codes));
          }, fail);
        } }, "Potvrdi"))));
      loadQr().then(function () { window.renderQr(qr, d.uri); }, fail);
    }
  }

  load().then(show, function (err) { app.replaceChildren(h("p", { class: "flash flash-error", text: err.message })); });
})();
