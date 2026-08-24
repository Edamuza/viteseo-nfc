# Bulk Card Sticker Template Export Design

**Date:** 2026-08-24  
**Status:** Approved design, pending implementation

## Goal

Add a dashboard action that downloads print-ready front and back PNG sticker
templates for every card in one ZIP archive. Each back must contain that card's
permanent QR URL.

## Admin Experience

The NFC Card Management dashboard gains a third export action beside
**Download QR codes (SVG)** and **Activation manifest**:

> Download all templates (PNG)

The action submits an authenticated `POST` request. It always exports every
card in the database, regardless of the current search and regardless of QR
configuration status.

If the database contains no cards, the request returns a 404 response with the
same behavior as the existing bulk QR export.

## Archive Contract

The response filename is:

```text
nfc-card-templates-YYYYMMDD-HHMMSS.zip
```

The archive uses a flat structure with two files per card:

```text
card-1-front.png
card-1-back.png
card-2-front.png
card-2-back.png
```

Names use the same zero-trimmed card number as the individual sticker download.
`CARD-0050` therefore produces `card-50-front.png` and
`card-50-back.png`.

No SVG, CSV, manifest, README, or nested folders are included.

## Artwork and QR Behavior

All PNG files are exactly 638 × 1011 pixels.

- The front uses the approved fixed Google review artwork.
- The front bytes are rendered once per request and reused for every card.
- The back uses the approved fixed artwork plus a card-specific QR.
- The QR encodes `Card::qrUrl()`, which resolves to `/q/{public_id}`.
- The QR never encodes `qr_destination`.
- Unconfigured cards are included; their permanent QR opens the setup page
  until a destination is configured.
- QR rendering retains level-M error correction, a four-module quiet zone,
  integer pixel scaling, and pure black/white modules.

## Architecture

`CardReviewStickerService` gains a bulk archive method that accepts an iterable
or collection of cards and returns the ZIP bytes. It reuses the existing
`frontPng()`, `backPng()`, and `downloadBasename()` behavior, while rendering
the front once.

`ExportController` receives `CardReviewStickerService` and exposes a dedicated
bulk export action. The action queries every card ordered by database ID,
returns 404 for an empty collection, and sends the generated archive with
private/no-store cache headers.

The authenticated admin route is:

```text
POST /admin/export/sticker-templates
```

The route name is:

```text
admin.export.sticker-templates
```

## Resource and Failure Handling

The ZIP is created only under `storage/app/tmp`; generated files are never
written to public storage.

The service removes its temporary archive after both successful generation and
exceptions. Any failure to create, populate, close, or read the archive raises
a `RuntimeException`.

The export remains synchronous. The expected current size of 50 cards is small
enough for one request, and avoiding queues keeps the feature simple. The
controller should not add a lower card limit than the existing database size.

## Security

- The endpoint remains inside the authenticated admin route group.
- The action uses POST and CSRF protection.
- Card numbers are validated by the existing safe basename logic before being
  used as ZIP entry names.
- The response uses `Content-Type: application/zip`.
- The response uses `Cache-Control: no-store, private`.
- No activation PIN or destination value is included.

## Testing

Automated tests must prove:

1. unauthenticated requests redirect to admin login;
2. the dashboard renders the exact POST action and CTA label;
3. all cards are exported, including unconfigured cards;
4. each card has exactly one correctly named front and back PNG;
5. every PNG is valid and exactly 638 × 1011;
6. fronts are byte-identical;
7. backs differ when only `public_id` differs;
8. changing only `qr_destination` does not change a back;
9. the response filename and private/no-store headers are correct;
10. an empty database returns 404;
11. temporary ZIP files are removed after success and failure.

The full Laravel test suite and `git diff --check` must pass before completion.
