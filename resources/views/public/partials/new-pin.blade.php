{{-- Shown only while the card still answers to the universal PIN: the first
     successful setup or unlock replaces it with the owner's own. --}}
@unless ($card->pinIsPersonalized())
  <label for="new_pin">Create your own 6-digit PIN</label>
  <input class="pin" id="new_pin" name="new_pin" type="tel" inputmode="numeric"
         maxlength="6" autocomplete="new-password" placeholder="New PIN" required>

  <label for="new_pin_confirmation">Repeat your new PIN</label>
  <input class="pin" id="new_pin_confirmation" name="new_pin_confirmation" type="tel" inputmode="numeric"
         maxlength="6" autocomplete="new-password" placeholder="Repeat new PIN" required>
  <p class="pin-hint">You&rsquo;ll use this PIN to change your card later. Keep it safe.</p>
@endunless
