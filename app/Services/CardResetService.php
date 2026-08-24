<?php

namespace App\Services;

use App\Enums\NfcMode;
use App\Models\Card;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Clearing a card's configuration.
 *
 * Both reset actions live here so that "what counts as the NFC payload" is one
 * list in one file. It used to be spelled out twice inside CardController and
 * was incomplete in both copies: a Contact-mode card reported as reset kept
 * serving the previous holder's name.
 *
 * When a mode is added, this file is the single place that has to learn about
 * it. A mode missed here means a card the admin was told is blank still
 * answers with someone else's data.
 */
class CardResetService
{
    /**
     * Clears everything the permanent NFC URL can serve, in every mode, and
     * returns the card to Links mode. The QR destination is untouched.
     *
     * Deliberately not surgical. The per-mode delete actions already cover
     * "remove just this one thing", which is what leaves this action free to
     * mean exactly what its confirmation message says.
     */
    public function resetNfcSide(Card $card): void
    {
        DB::transaction(function () use ($card) {
            $card->nfcLinks()->delete();

            if ($profile = $card->contactProfile) {
                if ($profile->photo_path) {
                    Storage::disk('public')->delete($profile->photo_path);
                }
                $profile->delete();
            }

            $card->forceFill([
                'nfc_mode'          => NfcMode::Links,
                'nfc_configured_at' => null,
                'nfc_phone_number'  => null,
                'nfc_email_address' => null,
                'nfc_email_subject' => null,
                'nfc_email_body'    => null,
                'nfc_page_title'    => null,
                'nfc_page_subtitle' => null,
                'nfc_logo_path'     => null,
            ])->save();
        });

        // The in-memory model may still hold the profile we just deleted.
        $card->unsetRelation('contactProfile');
    }
}
