(function () {
  'use strict';
  if (window.__spotPulseImageViewerLoaded) return;
  window.__spotPulseImageViewerLoaded = true;

  function esc(s) {
    return String(s || '').replace(/[&<>"']/g, function (c) {
      return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c];
    });
  }

  var box = document.createElement('div');
  box.className = 'image-lightbox';
  box.setAttribute('role', 'dialog');
  box.setAttribute('aria-modal', 'true');
  box.setAttribute('aria-label', 'Perbesar gambar');
  box.innerHTML = '<div class="image-lightbox-stage">' +
    '<div class="image-lightbox-toolbar">' +
      '<button type="button" data-action="zoom-out" aria-label="Perkecil">−</button>' +
      '<button type="button" data-action="reset" aria-label="Ukuran normal">1×</button>' +
      '<button type="button" data-action="zoom-in" aria-label="Perbesar">+</button>' +
      '<button type="button" data-action="close" aria-label="Tutup">×</button>' +
    '</div>' +
    '<img class="image-lightbox-img" alt="">' +
    '<div class="image-lightbox-hint">Klik gambar untuk zoom · Geser saat diperbesar · ESC untuk tutup</div>' +
  '</div>';
  document.body.appendChild(box);

  var stage = box.querySelector('.image-lightbox-stage');
  var img = box.querySelector('.image-lightbox-img');
  var scale = 1, minScale = 1, maxScale = 4, x = 0, y = 0;
  var dragging = false, startX = 0, startY = 0, baseX = 0, baseY = 0;

  function render() {
    img.style.transform = 'translate3d(' + x + 'px,' + y + 'px,0) scale(' + scale + ')';
  }
  function reset() { scale = 1; x = 0; y = 0; render(); }
  function setScale(next, clientX, clientY) {
    var old = scale;
    scale = Math.max(minScale, Math.min(maxScale, next));
    if (clientX != null && clientY != null && scale !== old) {
      var rect = img.getBoundingClientRect();
      var ox = clientX - (rect.left + rect.width / 2);
      var oy = clientY - (rect.top + rect.height / 2);
      var ratio = (scale / old) - 1;
      x -= ox * ratio;
      y -= oy * ratio;
    }
    if (scale === 1) { x = 0; y = 0; }
    render();
  }
  function open(source) {
    var src = source.getAttribute('data-zoom-src') || source.currentSrc || source.src;
    if (!src) return;
    img.src = src;
    img.alt = source.alt || 'Gambar';
    reset();
    box.classList.add('open');
    document.body.style.overflow = 'hidden';
  }
  function close() {
    box.classList.remove('open');
    document.body.style.overflow = '';
    img.removeAttribute('src');
    reset();
  }

  document.addEventListener('click', function (e) {
    var target = e.target.closest && e.target.closest('.zoomable-image');
    if (!target) return;
    e.preventDefault();
    e.stopPropagation();
    open(target);
  }, true);

  box.addEventListener('click', function (e) {
    if (e.target === box || e.target === stage) close();
  });
  box.querySelector('[data-action="close"]').addEventListener('click', close);
  box.querySelector('[data-action="reset"]').addEventListener('click', reset);
  box.querySelector('[data-action="zoom-in"]').addEventListener('click', function () { setScale(scale + .5); });
  box.querySelector('[data-action="zoom-out"]').addEventListener('click', function () { setScale(scale - .5); });

  img.addEventListener('dblclick', function (e) {
    e.preventDefault();
    setScale(scale > 1 ? 1 : 2, e.clientX, e.clientY);
  });
  stage.addEventListener('wheel', function (e) {
    if (!box.classList.contains('open')) return;
    e.preventDefault();
    setScale(scale + (e.deltaY < 0 ? .25 : -.25), e.clientX, e.clientY);
  }, { passive: false });
  img.addEventListener('pointerdown', function (e) {
    if (scale <= 1) return;
    dragging = true;
    img.classList.add('dragging');
    startX = e.clientX; startY = e.clientY; baseX = x; baseY = y;
    img.setPointerCapture(e.pointerId);
  });
  img.addEventListener('pointermove', function (e) {
    if (!dragging) return;
    x = baseX + (e.clientX - startX);
    y = baseY + (e.clientY - startY);
    render();
  });
  img.addEventListener('pointerup', function () {
    dragging = false; img.classList.remove('dragging');
  });
  img.addEventListener('pointercancel', function () {
    dragging = false; img.classList.remove('dragging');
  });
  document.addEventListener('keydown', function (e) {
    if (!box.classList.contains('open')) return;
    if (e.key === 'Escape') close();
    if (e.key === '+' || e.key === '=') setScale(scale + .5);
    if (e.key === '-') setScale(scale - .5);
    if (e.key === '0') reset();
  });
})();
