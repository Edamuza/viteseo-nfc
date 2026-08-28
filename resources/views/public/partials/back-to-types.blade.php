{{-- "Take me back to the list of types", as the secondary half of an actions
     row rather than a line of its own.

     For a card still being set up that list is the chooser at /n/{id}. For a
     live card the chooser is never shown there - the tap URL serves the card
     itself - so the list lives on the manage page, and sending a
     mid-mode-switch owner to /n/{id} would drop them on the very page they
     were trying to change. --}}
@include('public.partials.back-button', [
  'href' => $card->nfcModeIsConfigured()
      ? route('nfc.manage', $card->public_id)
      : route('nfc.show', $card->public_id),
  'label' => 'Back',
])
