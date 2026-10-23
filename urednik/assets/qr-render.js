// Renders every [data-qr] element as an SVG QR code, locally (no third-party service sees the secret).
window.renderQr = function (el, text) {
  var qr = qrcode(0, "M");
  qr.addData(text);
  qr.make();
  el.innerHTML = qr.createSvgTag({ cellSize: 5, margin: 2, scalable: true });
};
document.querySelectorAll("[data-qr]").forEach(function (el) {
  window.renderQr(el, el.getAttribute("data-qr"));
});
