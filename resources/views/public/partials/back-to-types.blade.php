{{-- "Take me back to the list of types."

     For a card still being set up that list is the chooser at /n/{id}. For a
     live card the chooser is never shown there - the tap URL serves the card
     itself - so the list lives on the manage page, and sending a
     mid-mode-switch owner to /n/{id} would drop them on the very page they
     were trying to change. --}}
<p style="text-align:center;margin:18px 0 0;font-size:.88rem">
  <a href="{{ $card->nfcModeIsConfigured()
        ? route('nfc.manage', $card->public_id)
        : route('nfc.show', $card->public_id) }}">&larr; Pick a different type</a>
</p>
