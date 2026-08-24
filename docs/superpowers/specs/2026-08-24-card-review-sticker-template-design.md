# Card Review Sticker Template — Design

Date: 2026-08-24
Status: Approved

## Goal

Add one admin action that downloads a print-ready front-and-back sticker package
for a card review card. The supplied Canva artwork remains fixed; the back QR is
replaced with the selected card's permanent QR URL.

## Admin experience

The card admin page's QR panel gains a **Download sticker template** action beside
the existing Download SVG and Download PNG actions.

One click downloads:

```text
card-50-template.zip
├── card-50-front.png
└── card-50-back.png
```

The number is derived from `CARD-0050` by lowercasing `card` and removing leading
zeroes from the numeric part. The public ID is deliberately omitted because the
card number is already unique and is the identifier used by the print workflow.

The action remains available before the QR destination is configured. The admin
page warns that the permanent QR still works but currently opens the setup page.

## Artwork and output

The two supplied Canva PNG exports are the canonical artwork:

- `Front-9261e5cd-8db2-4a0e-b891-5dbc4fbc1474.png`
- `Back-baedba41-b534-4dc8-a04e-40508931887c.png`

Implementation copies them into a versioned resource directory with stable names:

```text
resources/images/card-review-sticker/front.png
resources/images/card-review-sticker/back.png
```

Every output is normalised to **638 × 1011 pixels**, corresponding to the
standard portrait card face of **54 × 85.6 mm at 300 DPI**.

The front artwork is fixed. The back artwork is fixed except for the QR region.
The sample QR in the supplied back artwork is only a placement guide: generation
paints that module area white, preserves the surrounding grey scanner brackets,
and composites a fresh black-and-white QR for the selected card.

After normalisation, the clear region is the 426px square beginning at
`(106, 77)`. The generated QR is at most 410px square and is centred inside that
region. These values cover the supplied sample's black modules and white quiet
zone without touching the grey scanner brackets.

No card number, public ID, destination URL, or other variable text is printed on
the artwork.

## QR behaviour

The generated QR encodes `Card::qrUrl()`, the permanent `/q/{public_id}` URL. It
does not encode `qr_destination` directly. This is the same payload used by the
existing SVG and PNG downloads, so changing the destination never requires
reprinting the sticker.

The QR renderer:

- uses the existing level-M error correction and four-module quiet zone;
- renders modules at an integer pixel scale;
- never resamples the QR with interpolation;
- centres the generated QR inside the artwork's reserved square;
- keeps the grey scanner brackets outside the QR quiet zone.

The sticker generator delegates QR creation to `QrCodeService`; it does not carry
a second QR encoder. `QrCodeService::png()` and `pngForCard()` gain an optional
maximum-size argument while preserving their current 1200px default. The service
already chooses an integer module scale; the sticker requests 410px and centres
the actual returned square when the module count does not divide that limit
exactly.

## Architecture

`CardReviewStickerService` owns only sticker composition:

```php
public function frontPng(): string
public function backPng(Card $card): string
public function zipFor(Card $card): string
public function downloadBasename(Card $card): string
```

It loads the versioned source images, normalises their dimensions with GD,
replaces the back QR region, and returns PNG/ZIP bytes without writing permanent
generated files. Resizing preserves the source PNG alpha channel so the rounded
transparent corners remain intact.

The authenticated admin controller endpoint returns the ZIP with:

- `Content-Type: application/zip`;
- attachment filename `card-50-template.zip`;
- `Cache-Control: no-store, private`.

Temporary ZIP storage, if required by `ZipArchive`, is deleted in a `finally`
block. Failed source-image decoding or ZIP creation produces a logged server error
rather than a partial download.

## Security and reliability

- The route stays inside the existing authenticated admin card group.
- Route-model binding uses the card's public ID like the existing QR downloads.
- No user-controlled value is used as a filesystem path.
- ZIP entry names come only from the normalised card number.
- Templates are generated on demand; generated customer-specific files are not
  left in public storage.
- The endpoint is valid for configured and unconfigured cards because `/q/` is
  permanent in both states.

## Testing

Tests must prove:

- unauthenticated requests are rejected;
- the admin card page shows the new action;
- an unconfigured card still shows the action and the setup-page warning;
- the response is a no-store ZIP with the exact attachment filename;
- the ZIP contains exactly the two required PNG names;
- both PNGs decode and are exactly 638 × 1011;
- the front output does not vary between cards;
- the back output varies when the card's public ID varies;
- the QR replacement covers the sample QR while preserving the scanner brackets;
- QR pixels remain pure black or white in the reserved module area;
- changing `qr_destination` does not change the generated back image;
- changing `public_id` does change it;
- temporary ZIP files are removed after success and failure.

Mutation checks must demonstrate that tests fail if the back uses
`qr_destination`, if the sample QR is left untouched, if the filenames keep
leading zeroes, or if the route loses authentication.
