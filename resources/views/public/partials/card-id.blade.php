{{-- The owner's answer to "which card do I have?", shown on every page an
     owner works in because support and PIN resets need this exact ID and
     nothing on the physical card states it.

     The ID alone: the line explaining what it was for outweighed the thing it
     explained, and it sat at the bottom of every screen. --}}
<p class="cardid">Card ID: <code>{{ $card->public_id }}</code></p>
