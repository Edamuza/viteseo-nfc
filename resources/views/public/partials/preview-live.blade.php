@php
  // 'links' | 'contact' | 'phone' | 'profile'
  $pvKind = $kind ?? 'links';
  $pvAvatar = $avatarUrl ?? '';
  // Shown instead when the owner ticks "remove": a contact card whose own
  // photo goes falls back to the profile photo rather than to no photo.
  $pvAvatarFallback = $avatarFallback ?? '';
  $pvCover = $coverUrl ?? '';
@endphp
<aside class="pv-side" id="pv" data-kind="{{ $pvKind }}" data-avatar="{{ $pvAvatar }}" data-avatar-fallback="{{ $pvAvatarFallback }}" data-cover="{{ $pvCover }}">
  <div class="pv-bar">
    <span class="pv-lbl">Preview &mdash; what people see</span>
    <button class="pv-hide" type="button" id="pv-toggle">Hide</button>
  </div>
  <div class="pv-phone">
    <div class="pv-bezel"></div>
    {{-- Server-rendered, so a browser with JavaScript off shows a phone with a
         sentence in it rather than an empty black frame. The script replaces
         this on its first render. --}}
    <div class="pv-screen" id="pv-screen">
      <div class="pv-empty">Your card shows up here as you fill in the form.</div>
    </div>
  </div>
</aside>
@include('public.partials.preview-icons')

<script>
(function () {
  var side = document.getElementById('pv');
  var screen = document.getElementById('pv-screen');
  if (! side || ! screen) return;

  var kind = side.dataset.kind;
  var avatarUrl = side.dataset.avatar || '';
  var avatarFallback = side.dataset.avatarFallback || '';
  var coverUrl = side.dataset.cover || '';

  // The very table LinkPlatform matches on, handed over rather than
  // transcribed: "Auto" must guess the same icon the save will pick, and a
  // second copy of this list in JavaScript would drift the day a platform is
  // added on the server.
  var HOSTS = @json(\App\Services\LinkPlatform::hostMap());

  var icons = {};
  var iconStore = document.getElementById('pv-icons');
  if (iconStore) {
    // .content, not the element itself: a <template>'s children live in a
    // separate fragment that document-level queries never reach.
    iconStore.content.querySelectorAll('[data-k]').forEach(function (node) {
      icons[node.dataset.k] = node.innerHTML;
    });
  }

  function esc(value) {
    var node = document.createElement('div');
    node.textContent = value == null ? '' : value;
    return node.innerHTML;
  }

  function field(name) {
    var input = document.querySelector('[name="' + name + '"]');
    return input ? input.value.trim() : '';
  }

  /** What a blank field is filled with on save (data-fallback), if anything. */
  function fallback(name) {
    var input = document.querySelector('[name="' + name + '"]');
    return input && input.dataset.fallback ? input.dataset.fallback.trim() : '';
  }

  /** Mirrors LinkPlatform::host() - lowercase, no leading www, http(s) only. */
  function host(url) {
    try {
      var parsed = new URL(url);

      if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') return '';

      return parsed.hostname.toLowerCase().replace(/^www\./, '');
    } catch (error) {
      return '';
    }
  }

  /** Mirrors LinkPlatform::iconFor() - an explicit choice beats detection. */
  function iconKey(chosen, url) {
    if (chosen === 'none') return '';
    if (chosen) return chosen;

    var found = host(url);
    if (! found) return 'web';

    for (var key in HOSTS) {
      for (var i = 0; i < HOSTS[key].length; i++) {
        // Suffix-anchored like the server: linkedin.com.attacker.tld is not
        // LinkedIn, and the preview must not claim otherwise.
        if (found === HOSTS[key][i] || found.endsWith('.' + HOSTS[key][i])) return key;
      }
    }

    return 'web';
  }

  function mark(key) {
    return icons[key] ? '<span class="pv-ic">' + icons[key] + '</span>' : '';
  }

  function rows() {
    var list = [];

    document.querySelectorAll('.linkrow').forEach(function (row) {
      var title = row.querySelector('[name$="[title]"]');
      var url = row.querySelector('[name$="[url]"]');
      var icon = row.querySelector('select');

      title = title ? title.value.trim() : '';
      url = url ? url.value.trim() : '';

      // A row is worth drawing as soon as either half is filled; the label
      // falls back to the URL so a button never renders blank.
      if (title || url) list.push({ title: title || url, url: url, icon: icon ? icon.value : '' });
    });

    return list;
  }

  function buttons(list) {
    return list.map(function (row) {
      return '<div class="pv-link">' + mark(iconKey(row.icon, row.url)) + esc(row.title) + '</div>';
    }).join('');
  }

  function empty(message) {
    return '<div class="pv-empty">' + message + '</div>';
  }

  function renderLinks() {
    var title = field('page_title');
    var tagline = field('page_subtitle');
    var list = rows();
    var html = '';

    if (title || tagline) {
      html += '<div class="pv-head">'
        + (title ? '<p class="pv-name">' + esc(title) + '</p>' : '')
        + (tagline ? '<p class="pv-sub">' + esc(tagline) + '</p>' : '')
        + '</div>';
    }

    if (! list.length) return html + empty('Your buttons show up here as you type.');

    // One link is a straight redirect on the real page - no menu is ever
    // drawn - so the preview says that rather than drawing a menu of one.
    if (list.length === 1) {
      return html + empty('With only one link, a tap opens it straight away:<br><strong>'
        + esc(host(list[0].url) || list[0].title)
        + '</strong><br><br>Add a second link to show a menu instead.');
    }

    return html + buttons(list);
  }

  // A tap on a contact card opens the phone's own contact screen - there is
  // no page of ours in between - so this draws what the phone draws: the
  // face, the name, the details, and the phone's own two save buttons.
  // Blanks show what they will be filled with, mirroring ContactCard.
  function renderContact() {
    var name = [field('first_name'), field('last_name')].filter(Boolean).join(' ') || fallback('first_name');
    var company = field('company') || fallback('company');
    var job = field('job_title') || fallback('job_title');
    var phone = field('mobile_phone') || fallback('mobile_phone');
    var email = field('personal_email') || fallback('personal_email');
    var website = field('website');

    if (! name && ! company) return empty('Your contact card shows up here as you type.');

    var sub = [job, name ? company : ''].filter(Boolean).join(' \u00b7 ');
    var html = '<div class="pv-head"><span class="pv-ava"></span>'
      + '<p class="pv-name">' + esc(name || company) + '</p>'
      + (sub ? '<p class="pv-sub">' + esc(sub) + '</p>' : '')
      + '</div>';

    if (phone || email || website) {
      html += '<div class="pv-panel">'
        + (phone ? '<div class="pv-f"><span class="pv-k">Mobile</span><span class="pv-v">' + esc(phone) + '</span></div>' : '')
        + (email ? '<div class="pv-f"><span class="pv-k">Email</span><span class="pv-v">' + esc(email) + '</span></div>' : '')
        + (website ? '<div class="pv-f"><span class="pv-k">Website</span><span class="pv-v">' + esc(host(website) || website) + '</span></div>' : '')
        + '</div>';
    }

    return html + '<div class="pv-link pv-primary">Create New Contact</div>'
      + '<div class="pv-link">Add to Existing Contact</div>';
  }

  function renderPhone() {
    var number = field('nfc_phone_number');

    if (! number) return empty('Type your number to see the Call button.');

    return '<p class="pv-eyebrow">Call</p><p class="pv-big">' + esc(number) + '</p>'
      + '<div class="pv-link pv-primary">Call Now</div>';
  }

  function renderProfile() {
    var name = field('display_name');

    if (! name) return empty('Your profile shows up here as you type.');

    var headline = field('headline');
    var location = field('location');
    var tagline = field('tagline');

    var phone = field('phone');
    var email = field('email');

    var html = '<div class="pv-cover"></div>'
      + '<div class="pv-head"><span class="pv-ava"></span>'
      + '<p class="pv-name">' + esc(name) + '</p>'
      + (headline ? '<p class="pv-sub">' + esc(headline) + '</p>' : '')
      + (location ? '<p class="pv-sub">' + esc(location) + '</p>' : '')
      + (tagline ? '<p class="pv-sub">' + esc(tagline) + '</p>' : '')
      + '</div>';

    // The real page shows the value and puts the action beside it, so the
    // mock shows the value too - a pair of verbs would preview a page that
    // no longer exists.
    if (phone || email) {
      html += '<div class="pv-panel">'
        + (phone ? '<div class="pv-f"><span class="pv-k">Phone</span><span class="pv-v">' + esc(phone) + '</span></div>' : '')
        + (email ? '<div class="pv-f"><span class="pv-k">Email</span><span class="pv-v">' + esc(email) + '</span></div>' : '')
        + '</div>';
    }

    // The page's action row: Share on the left, the owner's own button (once
    // it has both text and a link, as the server insists) on the right.
    var ctaLabel = field('cta_label');
    var ctaUrl = field('cta_url');
    html += '<div class="pv-row"><div class="pv-link">Share</div>'
      + (ctaLabel && ctaUrl ? '<div class="pv-link pv-primary">' + esc(ctaLabel) + '</div>' : '')
      + '</div>';

    return html + buttons(rows());
  }

  function render() {
    // Looked up per render, not once at load: this script runs before the
    // form exists, so anything cached here would be cached as null.
    var removeAvatar = document.querySelector('[name="remove_avatar"], [name="remove_photo"]');

    screen.innerHTML = kind === 'contact' ? renderContact()
      : kind === 'phone' ? renderPhone()
      : kind === 'profile' ? renderProfile()
      : renderLinks();

    var shown = removeAvatar && removeAvatar.checked ? avatarFallback : avatarUrl;

    // The avatar disc and the cover band are two different things now: the
    // disc shows the owner's photo, the band shows the cover photo if there
    // is one, and only falls back to the blurred photo otherwise.
    screen.querySelectorAll('.pv-ava').forEach(function (node) {
      if (shown) {
        node.style.backgroundImage = 'url("' + shown.replace(/["\\]/g, '') + '")';
      }
    });

    var cover = coverUrl || shown;
    screen.querySelectorAll('.pv-cover').forEach(function (node) {
      node.style.backgroundImage = cover ? 'url("' + cover.replace(/["\\]/g, '') + '")' : '';
      node.classList.toggle('pv-cover-photo', !! coverUrl);
    });
  }

  document.addEventListener('input', render);
  document.addEventListener('change', render);
  // Link rows are added and removed by each form's own script, which fires no
  // event of its own; the timeout lets that handler finish first.
  document.addEventListener('click', function () { window.setTimeout(render, 0); });

  var toggle = document.getElementById('pv-toggle');
  toggle.addEventListener('click', function () {
    var collapsed = side.dataset.collapsed === '1';
    side.dataset.collapsed = collapsed ? '0' : '1';
    toggle.textContent = collapsed ? 'Hide' : 'Show';
  });

  /**
   * Everything that needs the form to exist.
   *
   * This partial is rendered ABOVE the form - on a phone the preview rides
   * along the top of the screen - so when this script runs, not one field it
   * reads has been parsed yet. Without the wait, a card that already HAS
   * details drew an empty phone until the owner touched an input, and the
   * photo picker was never wired up at all.
   */
  function start() {
    var avatar = document.getElementById('avatar');

    if (avatar) {
      avatar.addEventListener('change', function () {
        var file = avatar.files && avatar.files[0];
        if (! file) return;

        var reader = new FileReader();
        reader.onload = function () { avatarUrl = String(reader.result); render(); };
        reader.readAsDataURL(file);
      });
    }

    var cover = document.getElementById('cover');

    if (cover) {
      cover.addEventListener('change', function () {
        var file = cover.files && cover.files[0];
        if (! file) return;

        var reader = new FileReader();
        reader.onload = function () { coverUrl = String(reader.result); render(); };
        reader.readAsDataURL(file);
      });
    }

    render();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
})();
</script>
