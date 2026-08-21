@extends('layouts.admin')
@section('title','NFC Tools')
@section('body')

<h1>NFC tools</h1>
<p class="muted small">Read and program physical NFC tags using Web NFC.</p>

<div class="panel" id="unsupported" hidden>
  <h2>Web NFC is not available in this browser</h2>
  <p class="small" style="margin-bottom:8px">
    This browser does not expose <code>NDEFReader</code>, so tags cannot be read or written from here.
  </p>
  <p class="small muted" style="margin:0">
    Web NFC currently works in Chrome and Edge on <strong>Android</strong>, over HTTPS.
    It is <strong>not</strong> available on iPhone/iPad Safari, or on desktop browsers.
    On unsupported devices, use a native NFC writing app (for example “NFC Tools”) and write the
    card’s permanent NFC URL as an NDEF <em>URI</em> record.
  </p>
</div>

<div id="supported" hidden>

  {{-- ---------- READ ---------- --}}
  <div class="panel">
    <h2>Read a tag</h2>
    <p class="small muted" style="margin-top:0">Press Read, then hold a tag against the back of the phone.</p>
    <div class="row">
      <button class="btn btn-p" type="button" id="readbtn">Read NFC tag</button>
      <button class="btn" type="button" id="readstop" hidden>Stop</button>
      <span class="small muted" id="readstatus"></span>
    </div>
    <div id="readout" style="margin-top:14px"></div>
    <div class="note">
      Tag contents are displayed as text only. Nothing read from a tag is ever executed,
      followed automatically, or stored.
    </div>
  </div>

  {{-- ---------- WRITE ---------- --}}
  <div class="panel">
    <h2>Program a card</h2>

    <form method="GET" action="{{ route('admin.nfc-tools') }}" class="row" style="margin-bottom:14px">
      <input type="text" name="card" value="{{ request('card') }}" placeholder="Public ID (e.g. X7K92LM)" style="min-width:220px">
      <button class="btn" type="submit">Load card</button>
    </form>

    @if ($card)
      <p class="small" style="margin-bottom:4px"><strong>{{ $card->card_number }}</strong> &middot; <code>{{ $card->public_id }}</code></p>
      <p class="small muted" style="margin-top:0;margin-bottom:10px">This exact URL will be written as an NDEF URI record:</p>
      <code id="targeturl">{{ $card->nfcUrl() }}</code>

      <div class="row" style="margin-top:14px">
        <button class="btn btn-p" type="button" id="writebtn" data-url="{{ $card->nfcUrl() }}">Write this card to NFC</button>
        <span class="small muted" id="writestatus"></span>
      </div>
    @elseif (request('card'))
      <div class="err" style="margin-bottom:0">No card found with that public ID.</div>
    @else
      <p class="small muted">Enter a card’s public ID above, or use “Program NFC” from a card page.</p>
    @endif

    <div class="note">
      Writing the permanent <code>/n/{public_id}</code> URL is the normal and recommended operation.
      Tags are <strong>not</strong> made read-only, so a card can always be reprogrammed.
    </div>
  </div>

  {{-- ---------- ADVANCED ---------- --}}
  <div class="panel">
    <h2>Advanced: write a custom URL</h2>
    <div class="err" style="margin-bottom:14px">
      <strong>Warning.</strong> Writing a custom URL directly to the chip bypasses this system entirely —
      the redirect logic, the multi-link page, admin resets and any future analytics all stop applying
      to that tag. The card can only be brought back under management by reprogramming it with its
      permanent <code>/n/{public_id}</code> URL. Use this only for diagnostics.
    </div>
    <div class="row">
      <input type="url" id="customurl" placeholder="https://…" style="flex:1;min-width:240px">
      <button class="btn btn-d" type="button" id="customwrite">Write custom URL</button>
      <span class="small muted" id="customstatus"></span>
    </div>
  </div>
</div>

<script>
(function () {
  // Feature-detect rather than sniffing the user agent.
  var supported = (typeof window.NDEFReader === 'function');
  document.getElementById(supported ? 'supported' : 'unsupported').hidden = false;
  if (!supported) return;

  var esc = function (s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
    });
  };
  var set = function (id, msg) { document.getElementById(id).textContent = msg; };

  // ---------- READ ----------
  var ctrl = null;
  document.getElementById('readbtn').addEventListener('click', async function () {
    var out = document.getElementById('readout');
    out.innerHTML = '';
    set('readstatus', 'Waiting for a tag…');
    document.getElementById('readstop').hidden = false;

    try {
      var reader = new NDEFReader();
      ctrl = new AbortController();

      reader.onreadingerror = function () { set('readstatus', 'Could not read that tag. Try repositioning it.'); };

      reader.onreading = function (event) {
        set('readstatus', 'Tag read.');
        var rows = '<table><thead><tr><th>Record</th><th>Type</th><th>Content</th></tr></thead><tbody>';

        event.message.records.forEach(function (rec, i) {
          var content = '(binary)';
          try {
            if (rec.recordType === 'url' || rec.recordType === 'absolute-url') {
              content = new TextDecoder(rec.encoding || 'utf-8').decode(rec.data);
            } else if (rec.recordType === 'text') {
              content = new TextDecoder(rec.encoding || 'utf-8').decode(rec.data);
            } else if (rec.recordType === 'mime') {
              content = '(' + esc(rec.mediaType || 'mime') + ', ' + rec.data.byteLength + ' bytes)';
            }
          } catch (e) { content = '(undecodable)'; }

          // Always escaped, never rendered as a live link: tag content is untrusted.
          rows += '<tr><td class="mono">' + (i + 1) + '</td><td class="mono">' + esc(rec.recordType) +
                  '</td><td class="mono" style="word-break:break-all">' + esc(content) + '</td></tr>';
        });

        rows += '</tbody></table>';
        rows += '<p class="small muted">Serial: <span class="mono">' + esc(event.serialNumber || 'n/a') + '</span>. ' +
                'Web NFC does not report whether a tag is locked; a write attempt will fail if it is.</p>';
        out.innerHTML = rows;
      };

      await reader.scan({ signal: ctrl.signal });
    } catch (e) {
      set('readstatus', 'Scan failed: ' + (e && e.message ? e.message : 'unknown error'));
    }
  });

  document.getElementById('readstop').addEventListener('click', function () {
    if (ctrl) ctrl.abort();
    ctrl = null;
    this.hidden = true;
    set('readstatus', 'Stopped.');
  });

  // ---------- WRITE ----------
  async function writeUrl(url, statusId, btn) {
    if (!url) { set(statusId, 'Enter a URL first.'); return; }
    if (!/^https?:\/\//i.test(url)) { set(statusId, 'Only http:// and https:// URLs can be written.'); return; }

    set(statusId, 'Tap the NFC tag now…');
    if (btn) btn.disabled = true;

    try {
      var writer = new NDEFReader();
      await writer.write({ records: [{ recordType: 'url', data: url }] });
      set(statusId, 'Success — tag programmed.');
    } catch (e) {
      var m = (e && e.message) ? e.message : 'unknown error';
      if (e && e.name === 'NotAllowedError')      m = 'Permission denied, or the tag is locked/read-only.';
      else if (e && e.name === 'NotSupportedError') m = 'This tag is not compatible, or NFC is switched off.';
      else if (e && e.name === 'NetworkError')      m = 'Tag moved away too soon — hold it still and retry.';
      set(statusId, 'Failed: ' + m);
    } finally {
      if (btn) btn.disabled = false;
    }
  }

  var wb = document.getElementById('writebtn');
  if (wb) wb.addEventListener('click', function () { writeUrl(wb.dataset.url, 'writestatus', wb); });

  var cw = document.getElementById('customwrite');
  cw.addEventListener('click', function () {
    var u = document.getElementById('customurl').value.trim();
    if (!confirm('Write a CUSTOM URL to this tag?\n\nThis bypasses the NFC management system for this card.\n\n' + u)) return;
    writeUrl(u, 'customstatus', cw);
  });
})();
</script>
@endsection
