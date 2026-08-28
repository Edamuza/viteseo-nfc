{{-- One sample screen per card type, with made-up data.

     Sample rather than live because this is shown BEFORE the owner has picked
     a type, when there is nothing of theirs to draw. The markup deliberately
     matches what preview-live.blade.php builds from real values, so the thing
     they picked is the thing they then watch themselves fill in. --}}
@switch($mode->value)
  @case('contact')
    <div class="pv-head">
      <span class="pv-ava"></span>
      <p class="pv-name">Juan Dela Cruz</p>
      <p class="pv-sub">Owner</p>
      <p class="pv-sub">Juan&rsquo;s Coffee</p>
    </div>
    <div class="pv-link pv-primary">Save to Contacts</div>
    <div class="pv-row">
      <div class="pv-link">Call</div>
      <div class="pv-link">Email</div>
    </div>
    <div class="pv-panel">
      <div class="pv-f"><span class="pv-k">Mobile</span><span class="pv-v">+63 917 123 4567</span></div>
      <div class="pv-f"><span class="pv-k">Email</span><span class="pv-v">juan@example.com</span></div>
    </div>
    @break

  @case('phone')
    <p class="pv-eyebrow">Call</p>
    <p class="pv-big">+63 917 123 4567</p>
    <div class="pv-link pv-primary">Call Now</div>
    @break

  @case('profile')
    <div class="pv-cover"></div>
    <div class="pv-head">
      <span class="pv-ava"></span>
      <p class="pv-name">Juan Dela Cruz</p>
      <p class="pv-sub">Owner at Juan&rsquo;s Coffee</p>
      <p class="pv-sub">Manila, Philippines</p>
    </div>
    <div class="pv-link pv-primary">Save contact</div>
    <div class="pv-link"><span class="pv-ic"><x-platform-icon platform="web" /></span>Our website</div>
    <div class="pv-link"><span class="pv-ic"><x-platform-icon platform="facebook" /></span>Facebook</div>
    <div class="pv-link"><span class="pv-ic"><x-platform-icon platform="instagram" /></span>Instagram</div>
    @break

  @default
    <div class="pv-head">
      <p class="pv-name">Juan&rsquo;s Coffee</p>
      <p class="pv-sub">Fresh beans, Manila</p>
    </div>
    <div class="pv-link"><span class="pv-ic"><x-platform-icon platform="web" /></span>Order online</div>
    <div class="pv-link"><span class="pv-ic"><x-platform-icon platform="facebook" /></span>Facebook</div>
    <div class="pv-link"><span class="pv-ic"><x-platform-icon platform="instagram" /></span>Instagram</div>
    <div class="pv-link"><span class="pv-ic"><x-platform-icon platform="tiktok" /></span>TikTok</div>
@endswitch
