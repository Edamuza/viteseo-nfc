# Advanced Details Modal — Design

Date: 2026-08-24
Status: Approved

## Goal

Replace the public Profile page's inline expanding Advanced details section with
a centred modal matching the interaction pattern approved for the Share modal.

## Interaction

- The existing Advanced details pill remains in the same position.
- It becomes a link to `#advanced-details`.
- CSS `:target` displays a fixed overlay centred horizontally and vertically.
- A visible `×` link returns to `#` and closes the modal.
- The modal scrolls internally on short screens.
- No JavaScript is required. As with the Share modal, clicking the backdrop does
  not close it; the explicit close control does.

## Content

The modal contains:

1. A header titled “Advanced details”.
2. The welcome panel when at least one welcome field has content.
3. Permanent URL.
4. Last updated date.

The welcome panel supports eyebrow, heading, body, and CTA. Empty fields do not
render empty elements. The CTA renders as a link only when both its label and a
safe HTTP(S) destination are present. All cardholder-authored content is escaped.

The admin writer for welcome fields remains part of the later full Profile admin
increment; this change establishes the public rendering and modal behavior.

## Styling

The overlay uses the reusable modal primitives intended for the Share modal:

- dark translucent backdrop;
- white modal box;
- maximum width of 520px;
- `max-height: calc(100dvh - 48px)`;
- internal vertical scrolling;
- mobile-safe outer padding;
- heading and close control in a fixed visual header.

The welcome panel retains the dark-card treatment shown in the approved profile
mockup. The permanent URL and date remain in a light key/value card.

## Accessibility and safety

- The trigger has modal semantics through its destination and descriptive text.
- The close link has `aria-label="Close"`.
- The modal carries `role="dialog"` and an accessible title.
- The page remains usable without JavaScript.
- User-authored text uses escaped Blade output.
- CTA destinations pass through the existing read-side URL safety backstop.

## Testing

Feature tests must prove:

- Advanced details is an anchor targeting the modal, not an inline `<details>`.
- The modal has the correct dialog identity and close control.
- The CSS centres the targeted overlay and constrains its height.
- Welcome fields render only when populated and are escaped.
- Unsafe CTA schemes never become live links.
- Permanent URL and last-updated values remain present.
- The previous inline expansion markup is absent.
