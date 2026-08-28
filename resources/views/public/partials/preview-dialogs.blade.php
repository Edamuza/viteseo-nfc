{{-- The eye icon's answer: "what will people actually see when they tap it?"

     One dialog per card type, opened by any button carrying
     data-preview="{mode value}". Shared by the first-time chooser and the
     manage page, so both answer that question the same way. --}}
@foreach (\App\Enums\NfcMode::selfSetup() as $pvMode)
  <dialog class="pv-modal" id="pv-{{ $pvMode->value }}" aria-label="Preview: {{ $pvMode->label() }}">
    <div class="pv-box">
      <p class="pv-title">
        <span>{{ $pvMode->label() }}</span>
        <button class="pv-close" type="button" data-pv-close>Close</button>
      </p>
      <div class="pv-phone">
        <div class="pv-bezel"></div>
        <div class="pv-screen">@include('public.partials.preview-sample', ['mode' => $pvMode])</div>
      </div>
      <p class="pv-note">This is an example. Your own details go in once you pick this type.</p>
    </div>
  </dialog>
@endforeach

<script>
(function () {
  // Progressive enhancement: a browser without <dialog> keeps a working
  // chooser, so the eye buttons remove themselves rather than doing nothing.
  var supported = typeof HTMLDialogElement === 'function'
    && typeof HTMLDialogElement.prototype.showModal === 'function';

  document.querySelectorAll('[data-preview]').forEach(function (button) {
    if (! supported) { button.remove(); return; }

    button.addEventListener('click', function () {
      var dialog = document.getElementById('pv-' + button.dataset.preview);
      if (dialog) dialog.showModal();
    });
  });

  document.querySelectorAll('.pv-modal').forEach(function (dialog) {
    dialog.addEventListener('click', function (event) {
      if (event.target === dialog || event.target.hasAttribute('data-pv-close')) dialog.close();
    });
  });
})();
</script>
