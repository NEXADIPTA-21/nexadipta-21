/* Modal konfirmasi kustom: menggantikan window.confirm() / alert() bawaan browser.
   - Atribut onsubmit/onclick="...confirm('pesan')..." diganti otomatis ke modal admin.
   - API publik: window.uiConfirm(msg) → Promise<boolean>, window.uiAlert(msg) → Promise<void>
*/
(function () {
  var RE = /confirm\(\s*(['"])([\s\S]*?)\1\s*\)/;
  var ICON_WARN = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>';
  var ICON_ASK = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9.1 9a3 3 0 0 1 5.8 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>';
  var ICON_INFO = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>';

  function esc(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function describe(msg, mode) {
    var m = String(msg || '').toLowerCase();
    if (mode === 'alert') return { title: 'Informasi', ok: 'Mengerti', danger: false, cancel: false };
    if (m.indexOf('peringatan') === 0 || (m.indexOf('semua') > -1 && m.indexOf('hapus') > -1))
      return { title: 'Peringatan!', ok: 'Ya, Hapus Semua', danger: true, cancel: true };
    if (m.indexOf('hapus') > -1) return { title: 'Hapus Data?', ok: 'Ya, Hapus', danger: true, cancel: true };
    if (m.indexOf('reset') > -1) return { title: 'Reset Data?', ok: 'Ya, Reset', danger: true, cancel: true };
    if (m.indexOf('token') > -1) return { title: 'Buat Token?', ok: 'Ya, Lanjutkan', danger: false, cancel: true };
    if (m.indexOf('daftarkan') > -1) return { title: 'Daftarkan ke Polling?', ok: 'Ya, Daftarkan', danger: false, cancel: true };
    return { title: 'Konfirmasi', ok: 'Ya, Lanjutkan', danger: false, cancel: true };
  }

  function ask(msg, mode) {
    mode = mode || 'confirm';
    return new Promise(function (resolve) {
      var d = describe(msg, mode);
      var wrap = document.createElement('div');
      wrap.className = 'ui-modal-backdrop';
      var icon = mode === 'alert' ? ICON_INFO : (d.danger ? ICON_WARN : ICON_ASK);
      var cancelBtn = d.cancel ? '<button type="button" class="ui-cancel">Batal</button>' : '';
      wrap.innerHTML =
        '<div class="ui-modal' + (d.danger ? ' danger' : '') + '" role="alertdialog" aria-modal="true" aria-labelledby="uiModalTitle" aria-describedby="uiModalMsg">' +
        '<div class="ui-modal-icon">' + icon + '</div>' +
        '<h3 id="uiModalTitle">' + esc(d.title) + '</h3>' +
        '<p id="uiModalMsg">' + esc(msg) + '</p>' +
        '<div class="ui-modal-actions">' + cancelBtn +
        '<button type="button" class="ui-ok">' + esc(d.ok) + '</button></div></div>';
      var prevFocus = document.activeElement;
      function close(result) {
        document.removeEventListener('keydown', onKey, true);
        wrap.remove();
        if (prevFocus && prevFocus.focus) { try { prevFocus.focus(); } catch (e) {} }
        resolve(result);
      }
      function onKey(e) {
        if (e.key === 'Escape') { e.preventDefault(); close(mode === 'alert' ? true : false); }
        if (e.key === 'Tab') {
          var btns = wrap.querySelectorAll('button');
          var first = btns[0], last = btns[btns.length - 1];
          if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
          else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
        }
      }
      wrap.addEventListener('mousedown', function (e) { if (e.target === wrap) close(mode === 'alert' ? true : false); });
      var cancelEl = wrap.querySelector('.ui-cancel');
      if (cancelEl) cancelEl.addEventListener('click', function () { close(false); });
      wrap.querySelector('.ui-ok').addEventListener('click', function () { close(true); });
      document.addEventListener('keydown', onKey, true);
      document.body.appendChild(wrap);
      (cancelEl || wrap.querySelector('.ui-ok')).focus();
    });
  }

  function init() {
    document.querySelectorAll('[onsubmit*="confirm("], [onclick*="confirm("]').forEach(function (el) {
      ['onsubmit', 'onclick'].forEach(function (attr) {
        var v = el.getAttribute(attr);
        var m = v && RE.exec(v);
        if (m) { el.setAttribute('data-confirm', m[2]); el.removeAttribute(attr); }
      });
    });
    var forms = new Set();
    document.querySelectorAll('[data-confirm]').forEach(function (el) {
      var f = el.tagName === 'FORM' ? el : el.form;
      if (f) forms.add(f);
    });
    forms.forEach(function (form) {
      var approved = false;
      form.addEventListener('submit', function (e) {
        if (approved) { approved = false; return; }
        var sub = e.submitter;
        var msg = (sub && sub.getAttribute('data-confirm')) || form.getAttribute('data-confirm');
        if (!msg) return;
        e.preventDefault();
        ask(msg).then(function (ok) {
          if (!ok) return;
          approved = true;
          if (form.requestSubmit) form.requestSubmit(sub || undefined); else form.submit();
        });
      });
    });
  }

  window.uiConfirm = function (msg) { return ask(msg, 'confirm'); };
  window.uiAlert = function (msg) { return ask(msg, 'alert').then(function () {}); };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
