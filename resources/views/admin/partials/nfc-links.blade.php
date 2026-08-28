{{-- The NFC link editor: every row of the table as plain form fields, with no
     form of its own.

     Shared by Links mode and Profile mode, because both publish these same
     nfc_links rows - Links mode as buttons or a redirect, Profile mode as the
     public page's Accounts panel. Extracted rather than copied so that an
     admin editing a Profile card never has to switch the mode back to Links to
     reach the table, which would change what the live public URL serves while
     they work.

     It carries no <form> so that the enclosing one owns it: in Profile mode
     that is the profile form, and the single Save at its foot writes the
     profile and its links in one request. There is no per-row Save and no
     up/down arrows - the rows submit in the order they appear, so dragging one
     costs nothing until Save.

     Expects $card, $links (all of them, active and not) and $editing. --}}

<div class="section-sep">
  <div class="row" style="justify-content:space-between;align-items:center;margin-bottom:8px">
    <label class="fld" style="margin:0">Links</label>
    @if ($editing)
      <button class="btn btn-sm" type="button" id="lnk-add">Add link</button>
    @endif
  </div>

  @if ($editing)
    <p class="small muted" style="margin:0 0 10px">Drag a row by its handle, or hold it and move, to reorder.</p>
  @endif

  <div id="lnk-list" class="lnk-list">
    @foreach ($links as $i => $link)
      <div class="lnk">
        <button class="grab" type="button" aria-label="Reorder link {{ $i + 1 }}" tabindex="{{ $editing ? 0 : -1 }}">
          <svg viewBox="0 0 24 24" aria-hidden="true" fill="currentColor"><circle cx="9" cy="6" r="1.6"/><circle cx="15" cy="6" r="1.6"/><circle cx="9" cy="12" r="1.6"/><circle cx="15" cy="12" r="1.6"/><circle cx="9" cy="18" r="1.6"/><circle cx="15" cy="18" r="1.6"/></svg>
        </button>
        <input type="text" name="links[{{ $i }}][title]" value="{{ $link->title }}" maxlength="120"
               class="lnk-t" aria-label="Link label" placeholder="Label">
        <input type="url" name="links[{{ $i }}][url]" value="{{ $link->url }}" maxlength="500"
               class="lnk-u" aria-label="Link URL" placeholder="https://…">
        <select name="links[{{ $i }}][icon]" aria-label="Logo" class="lnk-i">
          <option value="">Auto logo</option>
          @foreach (\App\Services\LinkPlatform::iconChoices() as $key => $label)
            <option value="{{ $key }}" @selected($link->icon === $key)>{{ $label }}</option>
          @endforeach
        </select>
        <input type="hidden" name="links[{{ $i }}][is_active]" value="0">
        <input class="lnk-on" type="checkbox" name="links[{{ $i }}][is_active]" value="1"
               @checked($link->is_active) aria-label="Show this link" title="Show this link">
        <button class="rm" type="button" aria-label="Remove link">&times;</button>
      </div>
    @endforeach
  </div>

  @unless ($links->count())
    <p class="small muted" id="lnk-empty" style="margin:0">No links yet.</p>
  @endunless
</div>

@if ($editing)
  {{-- __I__ is replaced with a fresh index. It only has to be unique, not
       sequential: rows submit in document order, and the writer numbers them
       from that. --}}
  <template id="lnk-new">
    <div class="lnk">
      <button class="grab" type="button" aria-label="Reorder link">
        <svg viewBox="0 0 24 24" aria-hidden="true" fill="currentColor"><circle cx="9" cy="6" r="1.6"/><circle cx="15" cy="6" r="1.6"/><circle cx="9" cy="12" r="1.6"/><circle cx="15" cy="12" r="1.6"/><circle cx="9" cy="18" r="1.6"/><circle cx="15" cy="18" r="1.6"/></svg>
      </button>
      <input type="text" name="links[__I__][title]" maxlength="120" class="lnk-t" aria-label="Link label" placeholder="Label">
      <input type="url" name="links[__I__][url]" maxlength="500" class="lnk-u" aria-label="Link URL" placeholder="https://…">
      <select name="links[__I__][icon]" aria-label="Logo" class="lnk-i">
        <option value="">Auto logo</option>
        @foreach (\App\Services\LinkPlatform::iconChoices() as $key => $label)
          <option value="{{ $key }}">{{ $label }}</option>
        @endforeach
      </select>
      <input type="hidden" name="links[__I__][is_active]" value="0">
      <input class="lnk-on" type="checkbox" name="links[__I__][is_active]" value="1" checked
             aria-label="Show this link" title="Show this link">
      <button class="rm" type="button" aria-label="Remove link">&times;</button>
    </div>
  </template>
@endif

@if ($editing)
<script>
(function () {
  var list = document.getElementById('lnk-list');
  var add = document.getElementById('lnk-add');
  var tpl = document.getElementById('lnk-new');
  if (! list || ! add || ! tpl) return;

  var MAX = {{ \App\Services\NfcLinkWriter::MAX }};

  add.addEventListener('click', function () {
    if (list.children.length >= MAX) return;

    var empty = document.getElementById('lnk-empty');
    if (empty) empty.remove();

    list.insertAdjacentHTML('beforeend', tpl.innerHTML.replace(/__I__/g, Date.now().toString()));
    var row = list.lastElementChild;
    row.querySelector('.lnk-t').focus();
  });

  list.addEventListener('click', function (event) {
    var remove = event.target.closest('.rm');
    if (remove) remove.closest('.lnk').remove();
  });

  /* ---- Reordering ----------------------------------------------------
     No request, and no arrows: the rows submit in the order they sit in,
     so moving one is only ever a DOM move that Save then writes down.

     The handle drags immediately, because that is what a handle is for; a
     press anywhere else on the row starts a drag after a short hold, which
     is how it works on a phone, and any movement before the hold completes
     is treated as the scroll it probably was. */
  var row = null, hold = null, dragging = false;

  function begin(event) {
    dragging = true;
    row.classList.add('dragging');
    try { row.setPointerCapture(event.pointerId); } catch (error) { /* not fatal */ }
  }

  list.addEventListener('pointerdown', function (event) {
    var target = event.target.closest('.lnk');
    if (! target) return;

    row = target;

    if (event.target.closest('.grab')) { begin(event); return; }
    if (event.target.closest('input,select,button,label,textarea')) { row = null; return; }

    hold = window.setTimeout(function () { begin(event); }, 350);
  });

  list.addEventListener('pointermove', function (event) {
    if (! dragging) {
      // Moved before the hold finished: a scroll, not a drag.
      if (hold) { window.clearTimeout(hold); hold = null; }
      return;
    }

    event.preventDefault();

    var over = document.elementFromPoint(event.clientX, event.clientY);
    over = over && over.closest ? over.closest('.lnk') : null;
    if (! over || over === row || ! list.contains(over)) return;

    var box = over.getBoundingClientRect();
    list.insertBefore(row, event.clientY > box.top + box.height / 2 ? over.nextSibling : over);
  });

  function stop() {
    if (hold) { window.clearTimeout(hold); hold = null; }
    if (row) row.classList.remove('dragging');
    row = null;
    dragging = false;
  }

  list.addEventListener('pointerup', stop);
  list.addEventListener('pointercancel', stop);

  // The handle is a real button, so a keyboard can move a row too - the
  // arrows were the only way to do that before, and dropping them without
  // this would have left keyboard users unable to reorder at all.
  list.addEventListener('keydown', function (event) {
    var handle = event.target.closest('.grab');
    if (! handle) return;

    var up = event.key === 'ArrowUp';
    if (! up && event.key !== 'ArrowDown') return;

    event.preventDefault();
    var moving = handle.closest('.lnk');
    var neighbour = up ? moving.previousElementSibling : moving.nextElementSibling;
    if (! neighbour) return;

    list.insertBefore(up ? moving : neighbour, up ? neighbour : moving);
    handle.focus();
  });
})();
</script>
@endif
