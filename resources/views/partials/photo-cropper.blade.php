{{-- Pick the part of the photo that gets kept.

     Attaches to any <input type="file" data-crop>. Picking a file opens a
     round window over the image: drag to move it, use the slider to zoom, and
     Use photo writes a 512px square back into the same input through a
     DataTransfer, so the form submits the crop and nothing else changes.

     Two things fall out of cropping in the browser rather than on the server.
     The upload becomes a ~60 KB square instead of a 5 MB photo, which is the
     difference between fitting inside PHP's upload_max_filesize and not. And
     the browser has already applied the EXIF rotation, so what the owner
     framed is what gets stored - no orientation tag to interpret.

     Everything here is an enhancement. With JavaScript off, or on a browser
     without DataTransfer, the file uploads whole and the server centres and
     resizes it exactly as it always did. --}}
<dialog id="cropper" class="crop-modal" aria-label="Choose the part of the photo to keep">
  <div class="crop-box">
    <p class="crop-title">Move and zoom to frame your photo</p>
    <div class="crop-stage" id="crop-stage">
      <canvas id="crop-canvas" width="320" height="320"></canvas>
      <div class="crop-ring" aria-hidden="true"></div>
    </div>
    <label class="crop-zoom">
      <span>Zoom</span>
      <input type="range" id="crop-range" min="1" max="4" step="0.01" value="1" aria-label="Zoom">
    </label>
    <div class="crop-actions">
      <button class="crop-btn" type="button" id="crop-cancel">Cancel</button>
      <button class="crop-btn crop-go" type="button" id="crop-use">Use photo</button>
    </div>
  </div>
</dialog>

<style>
.crop-modal{border:0;background:transparent;padding:0;max-width:94vw}
.crop-modal::backdrop{background:rgba(16,24,40,.6)}
.crop-box{background:var(--surface);border-radius:var(--radius);padding:18px;box-shadow:0 20px 50px rgba(16,24,40,.3)}
.crop-title{margin:0 0 14px;font-size:.875rem;font-weight:500;color:var(--text-soft);text-align:center}
/* The stage is square and the ring is drawn over it, so what the circle
   frames is exactly what the canvas writes out. */
.crop-stage{position:relative;width:320px;max-width:78vw;aspect-ratio:1;margin:0 auto;
 background:#141414;border-radius:12px;overflow:hidden;touch-action:none;cursor:grab}
.crop-stage:active{cursor:grabbing}
#crop-canvas{width:100%;height:100%;display:block}
.crop-ring{position:absolute;inset:0;border-radius:12px;pointer-events:none;
 box-shadow:0 0 0 9999px rgba(20,20,20,.55) inset;
 -webkit-mask:radial-gradient(circle at 50% 50%, transparent 49.5%, #000 50%);
 mask:radial-gradient(circle at 50% 50%, transparent 49.5%, #000 50%)}
.crop-zoom{display:flex;align-items:center;gap:12px;margin:16px 0 0;font-size:.875rem;font-weight:500;color:var(--text-soft)}
.crop-zoom input{flex:1;accent-color:var(--accent)}
.crop-actions{display:flex;gap:10px;margin-top:16px}
/* Styled here rather than borrowed: this partial is included by both layouts
   and they call their buttons different things. */
.crop-btn{
  flex:1;min-height:44px;padding:11px 14px;border-radius:var(--radius-ctl,10px);
  border:1px solid var(--line-strong);background:var(--surface);color:var(--text);
  font:inherit;font-size:.875rem;font-weight:600;cursor:pointer;
}
.crop-btn:hover{background:var(--accent-soft);border-color:var(--accent)}
.crop-go{background:var(--accent);border-color:var(--accent);color:var(--on-accent,#19414b);flex:2}
.crop-go:hover{background:var(--accent-press);border-color:var(--accent-press)}
</style>

<script>
(function () {
  var dialog = document.getElementById('cropper');
  var inputs = document.querySelectorAll('input[type=file][data-crop]');
  if (! dialog || ! inputs.length) return;

  var supported = typeof HTMLDialogElement === 'function'
    && typeof HTMLDialogElement.prototype.showModal === 'function'
    && typeof DataTransfer === 'function'
    && !! document.createElement('canvas').toBlob;

  if (! supported) return;   // The plain upload still works; leave it alone.

  var canvas = document.getElementById('crop-canvas');
  var context = canvas.getContext('2d');
  var stage = document.getElementById('crop-stage');
  var range = document.getElementById('crop-range');

  var SIZE = canvas.width;   // the stage in canvas pixels
  var OUT = 512;             // what gets written back

  var image = null, target = null, zoom = 1, minZoom = 1, x = 0, y = 0;

  function clamp() {
    // The image may never leave a gap: at any zoom its edges stay outside the
    // stage, so the circle is always full.
    var w = image.naturalWidth * zoom, h = image.naturalHeight * zoom;
    x = Math.min(0, Math.max(x, SIZE - w));
    y = Math.min(0, Math.max(y, SIZE - h));
  }

  function draw() {
    clamp();
    context.fillStyle = '#141414';
    context.fillRect(0, 0, SIZE, SIZE);
    context.drawImage(image, x, y, image.naturalWidth * zoom, image.naturalHeight * zoom);
  }

  function open(file, input) {
    var reader = new FileReader();

    reader.onload = function () {
      var loaded = new Image();

      loaded.onload = function () {
        image = loaded;
        target = input;
        // Start with the photo covering the stage, centred.
        minZoom = SIZE / Math.min(loaded.naturalWidth, loaded.naturalHeight);
        zoom = minZoom;
        range.min = String(minZoom);
        range.max = String(minZoom * 4);
        range.value = String(minZoom);
        x = (SIZE - loaded.naturalWidth * zoom) / 2;
        y = (SIZE - loaded.naturalHeight * zoom) / 2;
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
      // A file we just wrote back is already square and already cropped.
      if (! file || file.name === 'cropped-photo.jpg') return;
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
    // The stage is drawn at SIZE canvas pixels but displayed at whatever CSS
    // width it got, so a pointer move has to be scaled into canvas space.
    var scale = SIZE / stage.getBoundingClientRect().width;
    x += (event.clientX - lastX) * scale;
    y += (event.clientY - lastY) * scale;
    lastX = event.clientX; lastY = event.clientY;
    draw();
  });

  ['pointerup', 'pointercancel'].forEach(function (name) {
    stage.addEventListener(name, function () { dragging = false; });
  });

  range.addEventListener('input', function () {
    if (! image) return;
    // Zoom about the middle of the stage, so the framing does not lurch.
    var next = parseFloat(range.value), middle = SIZE / 2;
    x = middle - (middle - x) * (next / zoom);
    y = middle - (middle - y) * (next / zoom);
    zoom = next;
    draw();
  });

  document.getElementById('crop-cancel').addEventListener('click', function () {
    if (target) target.value = '';   // Nothing framed, nothing chosen.
    dialog.close();
  });

  document.getElementById('crop-use').addEventListener('click', function () {
    if (! image || ! target) return;

    var out = document.createElement('canvas');
    out.width = out.height = OUT;
    var scale = OUT / SIZE;
    var ctx = out.getContext('2d');
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, OUT, OUT);
    ctx.drawImage(image, x * scale, y * scale,
      image.naturalWidth * zoom * scale, image.naturalHeight * zoom * scale);

    out.toBlob(function (blob) {
      if (! blob) { dialog.close(); return; }

      var carrier = new DataTransfer();
      carrier.items.add(new File([blob], 'cropped-photo.jpg', { type: 'image/jpeg' }));
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
