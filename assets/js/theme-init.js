// Loaded synchronously right after <meta name="color-scheme">, so a saved light/dark override
// applies before the first paint. No saved value = follow the system ("light dark").
(function () {
  try {
    var t = localStorage.getItem("ina-postaje-color-scheme");
    if (t === "light" || t === "dark") document.querySelector('meta[name="color-scheme"]').content = t;
  } catch (e) { /* storage blocked: follow the system */ }
})();
