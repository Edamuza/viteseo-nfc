{{-- Frame the part of the cover photo that gets kept.

     Attaches to any <input type="file" data-cover-crop>. Picking a file opens a
     WIDE window (the cover band's own 680x150 shape) over the image: drag to
     pan it — left, right, up or down — and use the slider to zoom, so the
     owner centres the part of the photo they want. Use photo writes a
     1360x300 JPEG back into the same input through a DataTransfer, so the
     form submits the crop and nothing else changes.

     The wide frame is what "crop it or centre it" means for a cover: the
     stored image already IS the cover, so the page just shows it — no
     object-position column, no re-crop on the server. As with the avatar
     cropper, everything here is an enhancement: with JavaScript off the file
     uploads whole and the server caps it to the same max edge. --}}
<dialog id="cover-cropper" class="cover-crop-modal" aria-label="Frame your cover photo">
  <div class="cover-crop-box">
    <p class="cover-crop-title">Move and zoom to frame your cover</p>
    <div class="cover-crop-stage" id="cover-crop-stage">
      <canvas id="cover-crop-canvas" width="680" height="150"></canvas>
    </div>
    <label class="cover-crop-zoom">
      <span>Zoom</span>
      <input type="range" id="cover-crop-range" min="1" max="4" step="0.01" value="1" aria-label="Zoom">
    </label>
    <div class="cover-crop-actions">
      <button class="cover-crop-btn" type="button" id="cover-crop-cancel">Cancel</button>
      <button class="cover-crop-btn cover-crop-go" type="button" id="cover-crop-use">Use cover</button>
    </div>
  </div>
</dialog>

<style>
.cover-crop-modal{border:0;background:transparent;padding:0;max-width:94vw}
.cover-crop-modal::backdrop{background:rgba(16,24,40,.6)}
.cover-crop-box{background:var(--surface);border-radius:var(--radius);padding:18px;box-shadow:0 20px 50px rgba(16,24,40,.3)}
.cover-crop-title{margin:0 0 14px;font-size:.875rem;font-weight:500;color:var(--text-soft);text-align:center}
/* The stage is the cover band's own ratio, so what it frames is exactly what
   the page will show. */
.cover-crop-stage{position:relative;width:100%;max-width:600px;aspect-ratio:680/150;margin:0 auto;
 background:#141414;border-radius:12px;overflow:hidden;touch-action:none;cursor:grab}
.cover-crop-stage:active{cursor:grabbing}
#cover-crop-canvas{width:100%;height:100%;display:block}
.cover-crop-zoom{display:flex;align-items:center;gap:12px;margin:16px 0 0;font-size:.875rem;font-weight:500;color:var(--text-soft)}
.cover-crop-zoom input{flex:1;accent-color:var(--accent)}
.cover-crop-actions{display:flex;gap:10px;margin-top:16px}
.cover-crop-btn{
  flex:1;min-height:44px;padding:11px 14px;border-radius:var(--radius-ctl,10px);
  border:1px solid var(--line-strong);background:var(--surface);color:var(--text);
  font:inherit;font-size:.875rem;font-weight:600;cursor:pointer;
}
.cover-crop-btn:hover{background:var(--accent-soft);border-color:var(--accent)}
.cover-crop-go{background:var(--accent);border-color:var(--accent);color:var(--on-accent,#19414b);flex:2}
.cover-crop-go:hover{background:var(--accent-press);border-color:var(--accent-press)}
</style>

<script>
(function () {
  var dialog = document.getElementById('cover-cropper');
  var inputs = document.querySelectorAll('input[type=file][data-cover-crop]');
  if (! dialog || ! inputs.length) return;

  var supported = typeof HTMLDialogElement === 'function'
    && typeof HTMLDialogElement.prototype.showModal === 'function'
    && typeof DataTransfer === 'function'
    && !! document.createElement('canvas').toBlob;

  if (! supported) return;   // The plain upload still works; leave it alone.

  var canvas = document.getElementById('cover-crop-canvas');
  var context = canvas.getContext('2d');
  var stage = document.getElementById('cover-crop-stage');
  var range = document.getElementById('cover-crop-range');

  var W = canvas.width;    // 680
  var H = canvas.height;   // 150
  var OUT_W = 1360, OUT_H = 300;   // 2x for retina

  var image = null, target = null, zoom = 1, minZoom = 1, x = 0, y = 0;

  function clamp() {
    // The image may never leave a gap: at any zoom its edges stay outside the
    // stage on both axes, so the band is always full (object-fit: cover).
    var w = image.naturalWidth * zoom, h = image.naturalHeight * zoom;
    x = Math.min(0, Math.max(x, W - w));
    y = Math.min(0, Math.max(y, H - h));
  }

  function draw() {
    clamp();
    context.fillStyle = '#141414';
    context.fillRect(0, 0, W, H);
    context.drawImage(image, x, y, image.naturalWidth * zoom, image.naturalHeight * zoom);
  }

  function open(file, input) {
    var reader = new FileReader();

    reader.onload = function () {
      var loaded = new Image();

      loaded.onload = function () {
        image = loaded;
        target = input;
        // Start with the photo covering the band, centred.
        minZoom = Math.max(W / loaded.naturalWidth, H / loaded.naturalHeight);
        zoom = minZoom;
        range.min = String(minZoom);
        range.max = String(minZoom * 4);
        range.value = String(minZoom);
        x = (W - loaded.naturalWidth * zoom) / 2;
        y = (H - loaded.naturalHeight * zoom) / 2;
        draw();
        dialog.showModal();
      };

      loaded.src = String(reader.result);
    };

    reader.readAsDataURL(file);
  }

  inputs.forEach(function (input) {
    input.addEventListener('change', function () {
      var file = input.files && input.files[0];
      // A file we just wrote back is already the crop; do not loop it.
      if (! file || file.name === 'cropped-cover.jpg') return;
      open(file, input);
    });
  });

  // ---- Panning ----
  var dragging = false, lastX = 0, lastY = 0;

  stage.addEventListener('pointerdown', function (event) {
    dragging = true; lastX = event.clientX; lastY = event.clientY;
    try { stage.setPointerCapture(event.pointerId); } catch (error) { /* not fatal */ }
  });

  stage.addEventListener('pointermove', function (event) {
    if (! dragging || ! image) return;
    event.preventDefault();
    // The canvas is drawn at W canvas pixels but displayed at whatever CSS
    // width it got, so a pointer move has to be scaled into canvas space.
    var scaleX = W / stage.getBoundingClientRect().width;
    var scaleY = H / stage.getBoundingClientRect().height;
    x += (event.clientX - lastX) * scaleX;
    y += (event.clientY - lastY) * scaleY;
    lastX = event.clientX; lastY = event.clientY;
    draw();
  });

  ['pointerup', 'pointercancel'].forEach(function (name) {
    stage.addEventListener(name, function () { dragging = false; });
  });

  range.addEventListener('input', function () {
    if (! image) return;
    // Zoom about the middle of the band, so the framing does not lurch.
    var next = parseFloat(range.value), mx = W / 2, my = H / 2;
    x = mx - (mx - x) * (next / zoom);
    y = my - (my - y) * (next / zoom);
    zoom = next;
    draw();
  });

  document.getElementById('cover-crop-cancel').addEventListener('click', function () {
    if (target) target.value = '';   // Nothing framed, nothing chosen.
    dialog.close();
  });

  document.getElementById('cover-crop-use').addEventListener('click', function () {
    if (! image || ! target) return;

    var out = document.createElement('canvas');
    out.width = OUT_W; out.height = OUT_H;
    var scale = OUT_W / W;
    var ctx = out.getContext('2d');
    ctx.fillStyle = '#141414';
    ctx.fillRect(0, 0, OUT_W, OUT_H);
    ctx.drawImage(image, x * scale, y * scale,
      image.naturalWidth * zoom * scale, image.naturalHeight * zoom * scale);

    out.toBlob(function (blob) {
      if (! blob) { dialog.close(); return; }

      var carrier = new DataTransfer();
      carrier.items.add(new File([blob], 'cropped-cover.jpg', { type: 'image/jpeg' }));
      target.files = carrier.files;
      // Let anything watching the field - the live preview - see the new file.
      target.dispatchEvent(new Event('change', { bubbles: true }));
      dialog.close();
    }, 'image/jpeg', 0.9);
  });

  dialog.addEventListener('click', function (event) {
    if (event.target === dialog) dialog.close();
  });
})();
</script>
