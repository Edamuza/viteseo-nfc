{{-- The owner's answer to "which card do I have?": shown on every page an
     owner works in, because support and PIN resets need this exact ID and
     nothing on the physical card states it. --}}
<p class="cardid">
  Card ID: <code>{{ $card->public_id }}</code>
  <span class="cardid-hint">&mdash; give this ID to support if you need help with your card.</span>
</p>
