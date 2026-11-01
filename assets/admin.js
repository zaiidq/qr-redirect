// Admin page helpers: QR rendering, PNG/SVG download, copy, edit toggle, delete confirm,
// local time display and search filter.
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

  function flashButton(btn) {
    btn.classList.add('done');
    var label = btn.getAttribute('title');
    btn.setAttribute('title', 'Copied!');
    setTimeout(function () {
      btn.classList.remove('done');
      btn.setAttribute('title', label);
    }, 1500);
  }

  function copyText(text, btn) {
    var fallback = function () {
      var ta = document.createElement('textarea');
      ta.value = text;
      ta.setAttribute('readonly', '');
      ta.style.position = 'fixed';
      ta.style.opacity = '0';
      document.body.appendChild(ta);
      ta.select();
      try { document.execCommand('copy'); flashButton(btn); } catch (e) { window.prompt('Copy this link:', text); }
      ta.remove();
    };
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(text).then(function () { flashButton(btn); }, fallback);
    } else {
      fallback();
    }
  }

  // QR previews + downloads
  document.querySelectorAll('.card[data-url]').forEach(function (card) {
    var url = card.getAttribute('data-url');
    var code = card.getAttribute('data-code') || 'code';
    var box = card.querySelector('.qr');
    var qr;
    try {
      qr = makeQr(url);
    } catch (e) {
      box.textContent = 'Could not render QR';
      return;
    }
    box.innerHTML = qr.createSvgTag({ cellSize: 4, margin: 8, scalable: true });

    card.querySelector('.js-png').addEventListener('click', function () {
      pngBlob(qr, function (blob) { download(blob, 'qr-' + code + '.png'); });
    });
    card.querySelector('.js-svg').addEventListener('click', function () {
      download(new Blob([svgText(qr)], { type: 'image/svg+xml' }), 'qr-' + code + '.svg');
    });

    // Edit toggle
    var editBtn = card.querySelector('.js-edit');
    var form = card.querySelector('.edit-form');
    var setOpen = function (open) {
      form.hidden = !open;
      editBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
      if (open) { form.querySelector('input[name="target_url"]').focus(); }
    };
    editBtn.addEventListener('click', function () { setOpen(form.hidden); });
    form.querySelector('.js-cancel').addEventListener('click', function () { form.reset(); setOpen(false); });
  });

  // Copy buttons
  document.querySelectorAll('.js-copy').forEach(function (btn) {
    btn.addEventListener('click', function () { copyText(btn.getAttribute('data-copy'), btn); });
  });

  // Confirm before delete
  document.querySelectorAll('form.js-confirm').forEach(function (form) {
    form.addEventListener('submit', function (ev) {
      if (!window.confirm(form.getAttribute('data-confirm') || 'Are you sure?')) {
        ev.preventDefault();
      }
    });
  });

  // Show last-scan times in the viewer's local time zone
  document.querySelectorAll('time[datetime]').forEach(function (t) {
    var d = new Date(t.getAttribute('datetime'));
    if (!isNaN(d)) {
      t.textContent = d.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
      t.title = t.getAttribute('datetime');
    }
  });

  // Search filter
  var filter = document.getElementById('filter');
  if (filter) {
    var cards = document.querySelectorAll('.card[data-search]');
    var none = document.querySelector('.no-match');
    filter.addEventListener('input', function () {
      var q = filter.value.trim().toLowerCase();
      var shown = 0;
      cards.forEach(function (c) {
        var hit = !q || c.getAttribute('data-search').indexOf(q) !== -1;
        c.hidden = !hit;
        if (hit) { shown++; }
      });
      if (none) { none.hidden = shown !== 0; }
    });
  }
})();
