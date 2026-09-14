<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="color-scheme" content="light">
@include('partials.exo2')
<title>{{ $profile->display_name }}</title>
{{-- The brand colour is derived from the avatar and normalised into a dark
     band, so --on-brand can be a fixed light value with no luminance branch.
     brandColor() guarantees a hex value; nothing else may be interpolated
     into this attribute. --}}
<style>
:root{
  --brand:{{ $profile->brandColor() }};
  --brand-soft:{{ $profile->brandColorSoftRgba() }};
  /* Text that sits ON the brand colour: the footer nav and the byline on the
     page behind the cards, and the owner's button beside Share, which is
     painted in --brand itself. DominantColor keeps every derived --brand
     dark, which is why a fixed light value reads on all of them. The Share
     control is not one of them - it uses var(--text), because it sits inside
     a white card unpainted. */
  --on-brand:#e8f1ec;
  --on-brand-dim:#a9c2b6;
  /* Neutrals from the ViteSEO palette; --brand above stays per-card,
     taken from the owner's own photo. */
  --card:#ffffff;
  --text:#111827;
  --soft:#374151;
  --muted:#6b7280;
  --line:#e5e7ea;
  --pill:#f1f2f5;
  --ink:#141414;
  --radius:20px;
}
*{box-sizing:border-box}
body{
  margin:0;background:var(--brand);color:var(--text);
  font:1rem/1.55 "Exo 2",-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
  -webkit-font-smoothing:antialiased;
  padding:26px 20px calc(38px + env(safe-area-inset-bottom));
}
.wrap{max-width:680px;margin:0 auto;position:relative}

/* The menu is one element in one place in the DOM, put where it belongs by
   CSS alone.

   On a phone it sits INSIDE the cover, in its top-right - which is why it is
   positioned against .wrap rather than parented to .cover: both .cover and
   .card clip their overflow, and a menu parented to either would have its
   dropdown sliced off at the bottom of the band.

   The card starts at the top of .wrap once this leaves the flow, so 14px from
   the wrap's top-right IS the cover's top-right. */
.topbar{position:absolute;top:14px;right:14px;z-index:30;margin:0}
details.menu{position:relative}
details.menu>summary{
  list-style:none;cursor:pointer;width:40px;height:40px;border-radius:50%;
  display:grid;place-items:center;
  /* Solid white, not a tint of whatever is behind it. This button lands on
     three different things - the brand-coloured page, the owner's own photo
     blurred into the cover, and any colour that photo happens to be - and a
     translucent fill disappeared into the light ones. The shadow is what
     keeps it off a white cover. */
  background:#fff;color:var(--text);
  box-shadow:0 2px 10px rgba(0,0,0,.22);
}
/* Tablet and up: the corner of the SCREEN, not the corner of the 680px column
   the page is centred in, where it read as floating in the middle of nowhere
   on a wide monitor. */
@media (min-width:768px){
  .topbar{position:fixed;top:20px;right:20px}
  .menu-panel{right:0}
}
details.menu>summary::-webkit-details-marker{display:none}
.menu-panel{
  position:absolute;right:0;top:47px;z-index:30;min-width:238px;
  background:var(--card);border-radius:14px;padding:7px;
  box-shadow:0 14px 38px rgba(0,0,0,.34);
}
.menu-panel a,.menu-panel button{
  display:flex;align-items:center;gap:11px;padding:11px 12px;border-radius:10px;
  color:var(--text);text-decoration:none;font:inherit;font-size:.94rem;
  width:100%;background:none;border:0;cursor:pointer;text-align:left;
}
.menu-panel a:hover,.menu-panel button:hover{background:#f3f5f7}
.menu-panel svg{width:16px;height:16px;fill:currentColor;flex:0 0 auto;color:var(--muted)}

.card{background:var(--card);border-radius:var(--radius);overflow:hidden;
 border:1px solid rgba(0,0,0,.06);margin-bottom:18px}
/* Cover: the avatar itself, blurred, when there is one — the brand colour at
   low alpha otherwise. The blur scale-up hides the soft vignette a blur
   filter leaves at the image edges. */
.cover{height:150px;background:var(--brand-soft);position:relative;overflow:hidden}
.cover-img{
  width:100%;height:100%;object-fit:cover;display:block;
  filter:blur(20px) saturate(1.15);transform:scale(1.2);opacity:.85;
}
/* The owner's own cover photo, uploaded and framed in the editor. It is shown
   clean - no blur, no scale - and centre-cropped to the band. The crop already
   happened when it was framed; object-fit:cover here only absorbs the small
   width differences between a phone and the 680px desktop column. */
.cover-photo{width:100%;height:100%;object-fit:cover;object-position:center;display:block}
.idblock{padding:0 28px 26px}
.avatar-row{margin-top:-54px;margin-bottom:16px}
.avatar-slot{position:relative;width:110px;height:110px}

/* The photo and the card's QR are two faces of one disc.
   The disc grows as it turns: a QR squeezed into the 110px the photo needs
   is too small to scan across a table, and it squares off as it grows
   because a circle would crop the corner finders off the code. */
.flip{width:110px;height:110px;perspective:700px;
 transition:transform .45s cubic-bezier(.2,.7,.3,1)}
.flip-in{position:relative;width:100%;height:100%;transform-style:preserve-3d;
 transition:transform .55s cubic-bezier(.2,.7,.3,1)}
.face{
  position:absolute;inset:0;border-radius:50%;border:4px solid var(--card);
  overflow:hidden;background:var(--card);transition:border-radius .45s ease;
  backface-visibility:hidden;-webkit-backface-visibility:hidden;
}
.face img{width:100%;height:100%;object-fit:cover;display:block}
/* White padding is the QR's quiet zone: the card QR is rendered flush to its
   edges, so the rounded corner would otherwise bite into real modules. */
.face.back{transform:rotateY(180deg);background:#fff;padding:12px;display:grid;place-items:center}
.face.back svg{width:100%;height:100%;display:block}
.avatar-fallback{
  width:100%;height:100%;display:grid;place-items:center;
  font-weight:640;font-size:2.1rem;color:#fff;background:var(--brand);
}
.avatar-slot.on .flip{transform:scale(1.36)}
.avatar-slot.on .flip-in{transform:rotateY(180deg)}
.avatar-slot.on .face{border-radius:16px}

/* The button always shows the face you are NOT looking at, so it reads as
   "tap for this" rather than as a state nobody can see. */
.qrtoggle{
  position:absolute;top:-2px;right:-2px;z-index:5;padding:0;cursor:pointer;
  width:34px;height:34px;border-radius:50%;overflow:hidden;
  background:#141a17;color:#fff;border:3px solid var(--card);
  display:grid;place-items:center;font-family:inherit;
  transition:transform .45s cubic-bezier(.2,.7,.3,1);
}
.qrtoggle svg{width:16px;height:16px;fill:currentColor;display:block}
.qrtoggle .i-av{display:none;width:100%;height:100%;font-size:.72rem;font-weight:650}
.qrtoggle .i-av img{width:100%;height:100%;object-fit:cover;display:block}
.avatar-slot.on .qrtoggle{transform:translate(20px,-20px)}
.avatar-slot.on .i-qr{display:none}
.avatar-slot.on .i-av{display:grid;place-items:center}
@media (prefers-reduced-motion:reduce){
  .flip,.flip-in,.face,.qrtoggle{transition:none}
}

h1{font-size:2.3125rem;line-height:1.15;font-weight:700;margin:0;letter-spacing:-.02em;overflow-wrap:anywhere}
.headline{color:var(--soft);margin:8px 0 0;font-size:1.1875rem;line-height:1.5;overflow-wrap:anywhere}
.meta{color:var(--muted);margin:5px 0 0;font-size:.9375rem}

.iconrow{display:flex;align-items:center;gap:17px;margin:18px 0 0;flex-wrap:wrap}
.iconrow a{color:#20262e;display:grid;place-items:center}
.iconrow svg{width:19px;height:19px;fill:currentColor;display:block}

.sep{border:0;border-top:1px solid var(--line);margin:18px 0 0}
.sep+.tagline{margin-top:16px}
.tagline{margin:20px 0 0;font-size:1.1875rem;line-height:1.5;color:var(--soft);overflow-wrap:anywhere}
.sharebtn{
  display:flex;align-items:center;justify-content:center;margin-top:22px;
  min-height:54px;border:1px solid #d9dde1;border-radius:14px;
  font-weight:600;font-size:1rem;color:var(--text);text-decoration:none;
}
.sharebtn:hover{background:#f7f8f9}
/* Share and the owner's own button share one row, half each: Share on the
   left, the owner's button on the right, where a thumb ends. It is the primary, painted in the page's own colour: --brand is taken
   from their photo, so a yellow photo makes a yellow button. DominantColor
   always keeps --brand dark, which is what lets --on-brand text read on it
   whatever the photo was. It sits on the white card, so it never vanishes
   into the brand-coloured page behind. */
.actions{display:flex;gap:10px;margin-top:22px}
.actions .sharebtn{flex:1;margin-top:0}
.ctabtn{
  flex:1;display:flex;align-items:center;justify-content:center;text-align:center;
  min-height:54px;padding:8px 14px;border:1px solid var(--brand);border-radius:14px;
  background:var(--brand);color:var(--on-brand);text-decoration:none;
  font-weight:600;font-size:1rem;line-height:1.25;overflow-wrap:anywhere;
}
.ctabtn:hover{filter:brightness(1.18)}
.ctabtn:focus-visible,.sharebtn:focus-visible{outline:2px solid var(--text);outline-offset:2px}
/* Contact details, shown rather than hidden behind a verb.
   A "Call" button alone answers only one of the two things a visitor wants:
   plenty of them are here to WRITE the number down, or to paste the address
   somewhere, and a button that only dials gives them nothing to read. So the
   value is the row - selectable text, at full size - and the action is a pill
   at the end of it. */
.reach{margin-top:22px;border-top:1px solid var(--line)}
.reach-row{display:flex;align-items:center;gap:13px;padding:14px 2px;border-bottom:1px solid var(--line)}
.reach-row .ri{flex:0 0 auto;width:20px;color:var(--muted)}
.reach-row .ri svg{width:20px;height:20px;fill:currentColor;display:block}
.reach-row .rv{flex:1;min-width:0;overflow-wrap:anywhere;font-size:1rem;color:var(--text)}
.reach-row .ra{
  flex:0 0 auto;padding:7px 15px;border-radius:999px;background:var(--pill);
  color:var(--text);text-decoration:none;font-size:.875rem;font-weight:600;
}
.reach-row .ra:hover{background:#e2e6ea}

.card-pad{padding:24px 28px}
h2{font-size:1.3125rem;line-height:1.25;font-weight:700;margin:0 0 6px;letter-spacing:-.01em}
.acct{display:flex;gap:15px;align-items:flex-start;padding:16px 0;border-bottom:1px solid var(--line)}
.acct:last-child{border-bottom:0}
/* Each mark sits in a disc of the page's own colour, like the owner's button:
   --brand from their photo, the mark in --on-brand. DominantColor keeps
   --brand dark, so the light mark reads on every card. Google's G keeps its
   own four colours - they are fixed on its paths - and still reads, because
   the brand is always far darker than any of them. */
.acct-ico{flex:0 0 auto;width:38px;height:38px;border-radius:50%;display:grid;place-items:center;
 background:var(--brand);color:var(--on-brand)}
.acct-ico svg{width:19px;height:19px;fill:currentColor;display:block}
.acct-name{font-size:1rem;font-weight:600;overflow-wrap:anywhere}
/* The whole row is the link: the mark, the owner's label, and an
   open-arrow at the end - the part a thumb looks for. Neither the address
   nor the kind of place is printed; the label is the owner's own words. */
.acct-go{color:inherit;text-decoration:none;align-items:center}
.acct-text{flex:1;min-width:0}
.acct-open{flex:0 0 auto;width:34px;height:34px;border-radius:999px;background:var(--pill);
 display:grid;place-items:center;color:var(--text)}
.acct-open svg{width:16px;height:16px;fill:currentColor;display:block}
.acct-go:hover .acct-open{background:#e2e6ea}
.acct-go:hover .acct-name{text-decoration:underline}
.acct-go:focus-visible{outline:2px solid var(--text);outline-offset:4px;border-radius:8px}
/* A destination that cannot be followed: the row stays, says so, and is not
   an anchor at all. */
.acct-dead{font-size:.85rem;color:var(--muted);display:block;margin-top:1px;font-style:italic}

.adv-trigger-wrap{margin:6px 0 0;text-align:center}
.adv-trigger{
  display:inline-flex;align-items:center;gap:8px;text-decoration:none;
  background:var(--pill);color:var(--text);border-radius:999px;padding:11px 19px;
  font-size:.92rem;font-weight:550;
}
.adv-trigger:hover{background:#dfe5e9}

/* Shared with the Share modal that lands in increment 3. CSS :target keeps
   both overlays usable without JavaScript. */
.modal{
  display:none;position:fixed;inset:0;z-index:60;
  background:rgba(6,10,8,.62);padding:24px;
  align-items:center;justify-content:center;overflow:auto;
}
.modal:target{display:flex}
.modal-box{
  background:var(--card);border-radius:22px;width:100%;max-width:520px;
  padding:26px;box-shadow:0 26px 70px rgba(0,0,0,.5);
  max-height:calc(100dvh - 48px);overflow:auto;
}
.modal-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin-bottom:20px}
.modal-head h2{font-size:1.36rem;font-weight:670;margin:0;letter-spacing:-.018em}
.modal-x{
  width:36px;height:36px;border-radius:50%;display:grid;place-items:center;
  color:var(--soft);text-decoration:none;font-size:1.15rem;flex:0 0 auto;
}
.modal-x:hover{background:#f1f3f5}

.qr-stage{background:var(--ink);border-radius:16px;padding:24px;display:grid;place-items:center}
.qr-card{background:#fff;border-radius:12px;overflow:hidden;width:232px}
.qr-card .qr-img{position:relative;padding:18px;display:grid;place-items:center}
.profile-qr{width:186px;height:186px;display:block}
.qr-card .dl{
  display:block;text-align:center;background:var(--pill);color:var(--text);
  padding:13px;font-size:.92rem;font-weight:560;text-decoration:none;
}
.qr-card .dl:hover{background:#dfe5e9}
.modal-row{
  display:flex;align-items:center;gap:14px;padding:18px 2px;
  border-bottom:1px solid var(--line);color:var(--text);text-decoration:none;font-size:1.02rem;
}
.modal-row:last-of-type{border-bottom:0}
.modal-row .ri{width:21px;color:var(--muted);flex:0 0 auto}
.modal-row .ri svg{width:21px;height:21px;fill:currentColor;display:block}
/* An outlined mark declares fill="none" and draws with its stroke; the
   blanket fill above would turn the globe and the Instagram square into
   solid blobs, so it is handed back to the attribute. */
.menu-panel svg[fill="none"]{fill:none}
.iconrow svg[fill="none"]{fill:none}
.acct-ico svg[fill="none"]{fill:none}
.modal-row .ri svg[fill="none"]{fill:none}
.modal-row .rt{font-weight:560;flex:1}
.modal-row .rc{color:var(--muted)}
.linkbar{
  display:flex;align-items:center;gap:12px;margin-top:8px;
  border:1px solid var(--line);border-radius:14px;padding:16px 18px;
}
.linkbar .u{flex:1;min-width:0;font-size:1rem;overflow-wrap:anywhere}
.copybtn{
  font:inherit;font-size:.96rem;font-weight:580;cursor:pointer;
  background:none;border:0;color:var(--text);display:inline-flex;align-items:center;gap:7px;
  flex:0 0 auto;
}

.welcome{
  background:linear-gradient(158deg,#262626,#141414);
  border-radius:18px;padding:28px;text-align:left;color:#f3f5f7;
}
.welcome-eyebrow{font-size:.96rem;font-weight:600;margin:0 0 10px;color:#a0aec0}
.welcome h3{font-size:1.42rem;font-weight:680;margin:0;letter-spacing:-.02em;line-height:1.26}
.welcome-copy{font-size:.96rem;color:#b8c4bc;margin:12px 0 0;white-space:pre-line}
.welcome-cta{
  display:inline-flex;align-items:center;gap:7px;margin-top:20px;
  background:#fff;color:#12190f;text-decoration:none;
  border-radius:11px;padding:12px 18px;font-weight:600;font-size:.96rem;
}
.welcome-cta:hover{background:#edf1ee}

.adv-body{background:var(--card);border:1px solid var(--line);border-radius:16px;
 margin-top:14px;padding:6px 24px;text-align:left}
.kv{display:flex;justify-content:space-between;gap:16px;padding:14px 0;border-bottom:1px solid var(--line);font-size:.94rem}
.kv:last-child{border-bottom:0}
.kv .k{color:var(--muted)}
.kv .v{text-align:right;overflow-wrap:anywhere}

footer{margin-top:32px;text-align:center}
footer nav{display:flex;gap:20px;justify-content:center;flex-wrap:wrap}
footer a{color:var(--on-brand);text-decoration:none;font-size:.86rem}
footer a:hover{text-decoration:underline}
.by{color:var(--on-brand-dim);font-size:.78rem;margin-top:14px}
.prose p{margin:0 0 13px;font-size:1rem;color:var(--soft);line-height:1.62}
.prose p:last-child{margin-bottom:0}
.toast{
  position:fixed;left:50%;bottom:30px;transform:translate(-50%,14px);
  background:#111814;color:#fff;border-radius:999px;padding:12px 20px;
  font-size:.92rem;font-weight:550;z-index:90;display:flex;align-items:center;gap:9px;
  opacity:0;visibility:hidden;transition:opacity .16s ease,transform .16s ease;
}
.toast.show{opacity:1;visibility:visible;transform:translate(-50%,0)}
.toast svg{width:17px;height:17px;fill:none;stroke:#6ee7a8;stroke-width:2.6;
 stroke-linecap:round;stroke-linejoin:round}
@media (prefers-reduced-motion:reduce){.toast{transition:none}}
</style>
</head>
<body>
<div class="wrap">

  <div class="topbar">
    <details class="menu">
      <summary aria-label="More options">
        <svg width="19" height="19" viewBox="0 0 24 24"><circle cx="5" cy="12" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="19" cy="12" r="2"/></svg>
      </summary>
      <div class="menu-panel">
        <a href="{{ route('nfc.profile.edit', $card->public_id) }}"><svg viewBox="0 0 24 24"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04a1 1 0 0 0 0-1.41l-2.34-2.34a1 1 0 0 0-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg> Edit your profile</a>
        <button type="button" data-copy="{{ $card->nfcUrl() }}"><svg viewBox="0 0 24 24"><path d="M16 1H4a2 2 0 0 0-2 2v14h2V3h12V1zm3 4H8a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h11a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2zm0 16H8V7h11v14z"/></svg> Copy link</button>
      </div>
    </details>
  </div>

  <section class="card">
    <div class="cover">
      @if ($profile->cover_path)
        <img class="cover-photo" src="{{ asset('storage/'.$profile->cover_path) }}" alt="" aria-hidden="true">
      @elseif ($profile->avatar_path)
        <img class="cover-img" src="{{ asset('storage/'.$profile->avatar_path) }}" alt="" aria-hidden="true">
      @endif
    </div>
    <div class="idblock">

      <div class="avatar-row">
        <div class="avatar-slot" id="avslot" data-qr-url="{{ $card->qrUrl() }}">
          <div class="flip">
            <div class="flip-in">
              <div class="face front">
                @if ($profile->avatar_path)
                  <img src="{{ asset('storage/'.$profile->avatar_path) }}" alt="">
                @else
                  <div class="avatar-fallback">{{ $profile->initials() }}</div>
                @endif
              </div>
              <div class="face back">{!! $cardQrSvg !!}</div>
            </div>
          </div>
          <button class="qrtoggle" type="button" id="qrflip" aria-pressed="false"
                  aria-label="Show the card QR code">
            <svg class="i-qr" viewBox="0 0 24 24" fill="currentColor"><path d="M3 3h7v7H3V3zm2 2v3h3V5H5zm9-2h7v7h-7V3zm2 2v3h3V5h-3zM3 14h7v7H3v-7zm2 2v3h3v-3H5zm9-2h3v3h-3v-3zm5 0h2v2h-2v-2zm-5 5h3v2h-3v-2zm5-2h2v4h-4v-2h2v-2z"/></svg>
            <span class="i-av">
              @if ($profile->avatar_path)
                <img src="{{ asset('storage/'.$profile->avatar_path) }}" alt="">
              @else
                {{ $profile->initials() }}
              @endif
            </span>
          </button>
        </div>
      </div>

      <h1>{{ $profile->display_name }}</h1>
      @if ($profile->headline)<p class="headline">{{ $profile->headline }}</p>@endif
      @if ($profile->username || $profile->location)
        <p class="meta">{{ trim(implode(' · ', array_filter([$profile->username, $profile->location]))) }}</p>
      @endif

      {{-- Brand links only. A row of six identical globes is noise rather than
           navigation, so unrecognised hosts appear in the panel below instead.

           This same filter is what keeps a defused link out of the row, and
           deliberately so: LinkPlatform::forUrl() returns 'web' for anything
           safeHref() would defuse, and the row carries no 'web' links. A bare
           mark has no text to explain that it leads nowhere, so the row has no
           dead state and needs none. The two rules are coupled;
           test_the_icon_row_never_carries_a_defused_link pins the consequence
           so a change to either is caught rather than shipping a dead mark. --}}
      @php
        // iconFor honours the author's dropdown choice and falls back to URL
        // detection. The row still carries brand marks only: a globe or a
        // "no logo" choice has no mark that belongs up here.
        $branded = $links->filter(fn ($l) => ! in_array(\App\Services\LinkPlatform::iconFor($l->icon, $l->url), ['web', \App\Services\LinkPlatform::NONE], true));
      @endphp
      {{-- Off for now (config/nfc.profile_icon_row): the Accounts panel below
           already carries every link. Kept, not deleted, with its tests. --}}
      @if (config('nfc.profile_icon_row') && $branded->isNotEmpty())
        <div class="iconrow">
          @foreach ($branded as $link)
            @php $platform = \App\Services\LinkPlatform::iconFor($link->icon, $link->url); @endphp
            <a href="{{ \App\Services\LinkPlatform::safeHref($link->url) }}" rel="noopener noreferrer nofollow ugc" target="_blank"
               aria-label="{{ \App\Services\LinkPlatform::label($platform) }}">
              <x-platform-icon :platform="$platform" />
            </a>
          @endforeach
        </div>
      @endif

      {{-- A rule between who they are (name, username, place) and what they
           say about themselves. Only when there is something below it. --}}
      @if ($profile->tagline)
        <hr class="sep">
        <p class="tagline">{{ $profile->tagline }}</p>
      @endif

      @php
        // tel: needs the number stripped to something dialable; the display
        // form keeps whatever spacing its owner typed.
        $dialable = $profile->phone ? \App\Rules\SafePhoneNumber::toDialable($profile->phone) : null;
      @endphp
      @if ($dialable || $profile->email)
        <div class="reach">
          @if ($dialable)
            <div class="reach-row">
              <span class="ri" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M6.6 10.8a15.1 15.1 0 0 0 6.6 6.6l2.2-2.2c.3-.3.7-.4 1-.2 1.2.4 2.4.6 3.6.6.6 0 1 .4 1 1V20c0 .6-.4 1-1 1A17 17 0 0 1 3 4c0-.6.4-1 1-1h3.5c.6 0 1 .4 1 1 0 1.3.2 2.5.6 3.6.1.4 0 .8-.2 1l-2.3 2.2z"/></svg></span>
              {{-- The number as the owner typed it, selectable: the tel: form
                   is stripped of spaces and is not what anyone writes down. --}}
              <span class="rv">{{ $profile->phone }}</span>
              <a class="ra" href="tel:{{ $dialable }}">Call</a>
            </div>
          @endif
          @if ($profile->email)
            <div class="reach-row">
              <span class="ri" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M20 4H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2zm0 4.2-8 4.8-8-4.8V6l8 4.8L20 6v2.2z"/></svg></span>
              <span class="rv">{{ $profile->email }}</span>
              <a class="ra" href="mailto:{{ $profile->email }}">Email</a>
            </div>
          @endif
        </div>
      @endif

      @php $cta = $profile->cta(); @endphp
      <div class="actions">
        <a class="sharebtn" href="#share" aria-haspopup="dialog" aria-controls="share">Share</a>
        @if ($cta)
          <a class="ctabtn" href="{{ $cta['href'] }}" rel="noopener noreferrer nofollow ugc" target="_blank">{{ $cta['label'] }}</a>
        @endif
      </div>
    </div>
  </section>

  @if ($links->isNotEmpty())
    <section class="card card-pad">
      <h2>Accounts</h2>
      {{-- No verification badges anywhere: nothing here proves account
           ownership, and a checkmark would assert a guarantee that does not
           exist. Hence the plain heading rather than a verified-profile framing. --}}
      @foreach ($links as $link)
        @php
          $platform = \App\Services\LinkPlatform::iconFor($link->icon, $link->url);
          $noLogo   = $platform === \App\Services\LinkPlatform::NONE;
          $href     = \App\Services\LinkPlatform::safeHref($link->url);
          // The label is always required, so the platform name only stands in
          // for a row stored before that rule existed.
          $kind     = $noLogo ? null : \App\Services\LinkPlatform::label($platform);
          $name     = trim((string) $link->title) ?: (string) $kind;
        @endphp
        @if ($href === '#')
          {{-- Defused, so not an anchor at all. href="#" resolves to this
               page, which with target="_blank" would open a second copy of
               the profile and read as a link that worked. The row is kept
               rather than dropped — silently discarding an administrator's
               row hides the problem instead of showing it. --}}
          <div class="acct">
            @unless ($noLogo)
              <span class="acct-ico"><x-platform-icon :platform="$platform" /></span>
            @endunless
            <div class="acct-text">
              <div class="acct-name">{{ $name }}</div>
              <span class="acct-dead">This link is unavailable</span>
            </div>
          </div>
        @else
          <a class="acct acct-go" href="{{ $href }}" rel="noopener noreferrer nofollow ugc" target="_blank">
            @unless ($noLogo)
              <span class="acct-ico"><x-platform-icon :platform="$platform" /></span>
            @endunless
            <div class="acct-text">
              <div class="acct-name">{{ $name }}</div>
            </div>
            <span class="acct-open" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M14 3h7v7h-2V6.41l-9.29 9.3-1.42-1.42L17.59 5H14V3zM5 5h6v2H5v12h12v-6h2v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2z"/></svg></span>
          </a>
        @endif
      @endforeach
    </section>
  @endif

  <div class="adv-trigger-wrap">
    {{-- The trigger is inline-flex with an 8px gap, which is a two-child
         layout: the chevron is what the gap spaces and what makes the pill read
         as a control rather than a stray word. Copied from the mockup verbatim
         rather than retyped - mistyped path data renders as a smear. --}}
    <a class="adv-trigger" href="#advanced-details" aria-haspopup="dialog" aria-controls="advanced-details">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 6 3 12l5 6"/><path d="m16 6 5 6-5 6"/></svg>
      Advanced details
    </a>
  </div>

  <footer>
    <nav aria-label="ViteSEO information">
      <a href="#about">What is this?</a>
      <a href="#terms">Terms of Service</a>
      <a href="#privacy">Privacy Policy</a>
      <a href="{{ route('nfc.manage', $card->public_id) }}">Manage this card</a>
    </nav>
    <div class="by">Powered by ViteSEO</div>
  </footer>

</div>

<div class="modal" id="share" role="dialog" aria-modal="true" aria-labelledby="share-title">
  <div class="modal-box">
    <div class="modal-head">
      <h2 id="share-title">Share Profile</h2>
      <a class="modal-x" href="#" aria-label="Close">&#10005;</a>
    </div>

    <div class="qr-stage">
      <div class="qr-card">
        <div class="qr-img">
          <img class="profile-qr" src="{{ route('nfc.profile.qr', $card->public_id) }}" data-qr-url="{{ $card->nfcUrl() }}" alt="QR code for this profile">
        </div>
        <a class="dl" href="{{ route('nfc.profile.qr', $card->public_id) }}" download>Download QR</a>
      </div>
    </div>

    <a class="modal-row" href="{{ $shareMailto }}">
      <span class="ri"><svg viewBox="0 0 24 24"><path d="M12 2a10 10 0 1 0 4.5 18.94v-2.2A7.8 7.8 0 1 1 19.8 12v.9a1.4 1.4 0 0 1-2.8 0V8.2h-1.7v1a3.9 3.9 0 1 0 .5 5.6 3.2 3.2 0 0 0 5.7-1.9V12A10 10 0 0 0 12 2zm0 12.2A2.2 2.2 0 1 1 14.2 12 2.2 2.2 0 0 1 12 14.2z"/></svg></span>
      <span class="rt">Share via Email</span><span class="rc">&rsaquo;</span>
    </a>
    <a class="modal-row" href="{{ route('nfc.profile.vcard', $card->public_id) }}">
      <span class="ri"><svg viewBox="0 0 24 24"><path d="M20 4H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2zM9 8.5a2 2 0 1 1 0 4 2 2 0 0 1 0-4zm3.5 8h-7v-.6c0-1.2 1.6-2.2 3.5-2.2s3.5 1 3.5 2.2v.6zM19 15h-4v-1.5h4V15zm0-3h-4v-1.5h4V12zm0-3h-4V7.5h4V9z"/></svg></span>
      <span class="rt">Add to contacts (vCard)</span><span class="rc">&rsaquo;</span>
    </a>
    <div class="linkbar">
      <span class="u">{{ $card->nfcUrl() }}</span>
      <button class="copybtn" type="button" data-copy="{{ $card->nfcUrl() }}">Copy link
        <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M16 1H4a2 2 0 0 0-2 2v14h2V3h12V1zm3 4H8a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h11a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2zm0 16H8V7h11v14z"/></svg>
      </button>
    </div>
  </div>
</div>

@php
  $welcomeCtaHref = filled($profile->welcome_cta_url)
      ? \App\Services\LinkPlatform::safeHref($profile->welcome_cta_url)
      : '#';
  $hasWelcomeCta = filled($profile->welcome_cta_label) && $welcomeCtaHref !== '#';
  $hasWelcome = filled($profile->welcome_eyebrow)
      || filled($profile->welcome_heading)
      || filled($profile->welcome_body)
      || $hasWelcomeCta;
@endphp

<div class="modal" id="advanced-details" role="dialog" aria-modal="true" aria-labelledby="advanced-details-title">
  <div class="modal-box">
    <div class="modal-head">
      <h2 id="advanced-details-title">Advanced details</h2>
      <a class="modal-x" href="#" aria-label="Close">&#10005;</a>
    </div>

    @if ($hasWelcome)
      <section class="welcome">
        @if (filled($profile->welcome_eyebrow))
          <p class="welcome-eyebrow">{{ $profile->welcome_eyebrow }}</p>
        @endif
        @if (filled($profile->welcome_heading))
          <h3>{{ $profile->welcome_heading }}</h3>
        @endif
        @if (filled($profile->welcome_body))
          <p class="welcome-copy">{{ $profile->welcome_body }}</p>
        @endif
        @if ($hasWelcomeCta)
          <a class="welcome-cta" href="{{ $welcomeCtaHref }}" rel="noopener noreferrer nofollow ugc" target="_blank">{{ $profile->welcome_cta_label }}</a>
        @endif
      </section>
    @endif

    <div class="adv-body">
      {{-- The permanent URL, never the card number: publishing a sequential
           internal id tells a visitor how many cards exist. --}}
      <div class="kv"><span class="k">Permanent URL</span><span class="v">{{ $card->nfcUrl() }}</span></div>
      <div class="kv"><span class="k">Last updated</span><span class="v">{{ $profile->updated_at->format('j F Y') }}</span></div>
    </div>
  </div>
</div>
@foreach (['about', 'terms', 'privacy'] as $sitePageKey)
  @php $sitePage = $sitePages->get($sitePageKey); @endphp
  @if ($sitePage)
    <div class="modal" id="{{ $sitePageKey }}" role="dialog" aria-modal="true" aria-labelledby="{{ $sitePageKey }}-title">
      <div class="modal-box">
        <div class="modal-head">
          <h2 id="{{ $sitePageKey }}-title">{{ $sitePage->title }}</h2>
          <a class="modal-x" href="#" aria-label="Close">&#10005;</a>
        </div>
        <div class="prose">
          @foreach ($sitePage->paragraphs() as $paragraph)
            <p>{{ $paragraph }}</p>
          @endforeach
        </div>
      </div>
    </div>
  @endif
@endforeach
<div class="toast" id="toast" role="status" aria-live="polite">
  <svg viewBox="0 0 24 24"><path d="m5 13 4 4L19 7"/></svg>
  <span>Link copied</span>
</div>
<script>
function copyFallback(value, done) {
  var field = document.createElement('textarea');
  field.value = value;
  field.setAttribute('readonly', '');
  field.style.position = 'fixed';
  field.style.opacity = '0';
  document.body.appendChild(field);
  field.select();
  if (document.execCommand('copy')) { done(); }
  document.body.removeChild(field);
}
/**
 * The avatar turns over to show the card's QR.
 *
 * It used to be a <details> popover that only the same 34px button could
 * close, which is what made it feel stuck: people tapped the page, nothing
 * happened, and the code stayed up. Anywhere off the disc puts the photo
 * back now, as does Escape.
 */
(function () {
  var slot = document.getElementById('avslot');
  var button = document.getElementById('qrflip');
  if (! slot || ! button) return;

  function show(open) {
    slot.classList.toggle('on', open);
    button.setAttribute('aria-pressed', open ? 'true' : 'false');
    button.setAttribute('aria-label', open ? 'Show the photo again' : 'Show the card QR code');
  }

  button.addEventListener('click', function () {
    show(! slot.classList.contains('on'));
  });

  // The button lives inside the slot, so its own click never reaches this.
  document.addEventListener('click', function (event) {
    if (! slot.contains(event.target)) show(false);
  });

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') show(false);
  });
})();

document.querySelectorAll('[data-copy]').forEach(function (btn) {
  btn.addEventListener('click', function () {
    var value = btn.dataset.copy;
    var toast = document.getElementById('toast');
    var done = function () {
      toast.classList.add('show');
      setTimeout(function () { toast.classList.remove('show'); }, 1900);
    };
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(value).then(done)
        .catch(function () { copyFallback(value, done); });
      return;
    }
    copyFallback(value, done);
  });
});
</script>
</body>
</html>
