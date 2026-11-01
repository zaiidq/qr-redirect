// Admin page helpers: QR rendering, PNG/SVG download, copy-to-clipboard, confirm-before-delete.
(function () {
  'use strict';

  // Encode text as UTF-8 bytes (safe for any BASE_URL).
  if (qrcode.stringToBytesFuncs && qrcode.stringToBytesFuncs['UTF-8']) {
    qrcode.stringToBytes = qrcode.stringToBytesFuncs['UTF-8'];
  }

  function makeQr(text) {
    var qr = qrcode(0, 'Q'); // type 0 = auto size, error correction level Q
    qr.addData(text);
    qr.make();
    return qr;
  }

  function download(blob, filename) {
    var url = URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(function () { URL.revokeObjectURL(url); }, 2000);
  }

  function pngBlob(qr, callback) {
    var quiet = 4; // quiet zone in modules (QR standard)
    var count = qr.getModuleCount();
    var total = count + quiet * 2;
    var cell = Math.max(1, Math.round(1000 / total));
    var size = total * cell;
    var canvas = document.createElement('canvas');
    canvas.width = size;
    canvas.height = size;
    var ctx = canvas.getContext('2d');
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, size, size);
    ctx.fillStyle = '#000000';
    for (var r = 0; r < count; r++) {
      for (var c = 0; c < count; c++) {
        if (qr.isDark(r, c)) {
          ctx.fillRect((c + quiet) * cell, (r + quiet) * cell, cell, cell);
        }
      }
    }
    canvas.toBlob(callback, 'image/png');
  }

  function svgText(qr) {
    // cellSize 10, quiet zone of 4 modules
    return '<?xml version="1.0" encoding="UTF-8"?>\n' + qr.createSvgTag({ cellSize: 10, margin: 40 });
  }

  function copyText(text, btn) {
    var done = function () {
      var old = btn.textContent;
      btn.textContent = 'تم النسخ ✓';
      setTimeout(function () { btn.textContent = old; }, 1500);
    };
    var fallback = function () {
      var ta = document.createElement('textarea');
      ta.value = text;
      ta.setAttribute('readonly', '');
      ta.style.position = 'fixed';
      ta.style.opacity = '0';
      document.body.appendChild(ta);
      ta.select();
      try { document.execCommand('copy'); done(); } catch (e) { window.prompt('انسخ الرابط:', text); }
      ta.remove();
    };
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(text).then(done, fallback);
    } else {
      fallback();
    }
  }

  document.querySelectorAll('[data-url]').forEach(function (card) {
    var url = card.getAttribute('data-url');
    var code = card.getAttribute('data-code') || 'code';
    var qr;
    try {
      qr = makeQr(url);
    } catch (e) {
      card.querySelector('.qr').textContent = 'تعذر إنشاء QR';
      return;
    }
    // Preview (scalable SVG, sized by CSS)
    card.querySelector('.qr').innerHTML = qr.createSvgTag({ cellSize: 4, margin: 16, scalable: true });

    var png = card.querySelector('.js-png');
    if (png) {
      png.addEventListener('click', function () {
        pngBlob(qr, function (blob) { download(blob, 'qr-' + code + '.png'); });
      });
    }
    var svg = card.querySelector('.js-svg');
    if (svg) {
      svg.addEventListener('click', function () {
        download(new Blob([svgText(qr)], { type: 'image/svg+xml' }), 'qr-' + code + '.svg');
      });
    }
  });

  document.querySelectorAll('.js-copy').forEach(function (btn) {
    btn.addEventListener('click', function () { copyText(btn.getAttribute('data-copy'), btn); });
  });

  document.querySelectorAll('form.js-confirm').forEach(function (form) {
    form.addEventListener('submit', function (ev) {
      if (!window.confirm(form.getAttribute('data-confirm') || 'هل أنت متأكد؟')) {
        ev.preventDefault();
      }
    });
  });
})();
