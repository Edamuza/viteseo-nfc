# Exo 2

`exo2-latin.woff2` is the latin subset of Exo 2, the single typeface of the
ViteSEO design system (see `viteseo-platform2.0/frontend/src/app/layout.tsx`,
which loads it through `next/font/google`).

It is a **variable** font: this one 40 KB file serves every weight the pages
use, 400 through 700. That is why the `@font-face` rules declare a weight
*range* rather than one rule per weight.

Self-hosted rather than linked from Google, for the same reason the platform
self-hosts it: a tapped card page must not wait on a third party to paint,
and a stranger tapping someone's card should not be announced to one.

Exo 2 is licensed under the SIL Open Font License 1.1
(https://openfontlicense.org), which permits redistribution with this notice.
