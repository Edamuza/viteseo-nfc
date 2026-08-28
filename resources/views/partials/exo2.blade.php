{{-- Exo 2 — the ViteSEO design system's single typeface.

     One variable file covers 400 through 700, which is why the rule declares a
     weight RANGE instead of one @font-face per weight. Self-hosted for the same
     reason the platform self-hosts it through next/font: a tapped card page
     must not wait on a third party to paint, and a stranger tapping someone's
     card should not be announced to one.

     swap, not block: the page paints immediately in the system font and
     changes to Exo 2 when it lands. On a card page that is the right trade -
     text now beats the right text a moment later. --}}
<link rel="preload" href="{{ asset('fonts/exo2-latin.woff2') }}" as="font" type="font/woff2" crossorigin>
<style>
@font-face{
  font-family:"Exo 2";
  font-style:normal;
  font-weight:400 700;
  font-display:swap;
  src:url("{{ asset('fonts/exo2-latin.woff2') }}") format("woff2");
  unicode-range:U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+0304,U+0308,U+0329,U+2000-206F,U+20AC,U+2122,U+2191,U+2193,U+2212,U+2215,U+FEFF,U+FFFD;
}
</style>
