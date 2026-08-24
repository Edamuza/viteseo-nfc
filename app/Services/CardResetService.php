<?php

namespace App\Services;

use App\Enums\NfcMode;
use App\Models\Card;
use Illuminate\Support\Facades\DB;

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
 * answers with someone else's data. Profile mode was added in increment 2 and
 * needed exactly these four lines.
 *
 * Each public method delegates to a private helper that runs exactly one
 * database transaction, then deletes the contact photo and the profile avatar
 * and unsets both in-memory relations. File deletion is deliberately post-commit
 * for both entry points:
 * filesystem deletes do not roll back, and a file removed while the profile
 * row survives would show a broken image on a live contact page.
 */
class CardResetService
{
    public function __construct(private readonly PublicImageStorage $photos) {}

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
        $this->reset($card);
    }

    /**
     * resetNfcSide() plus the QR destination.
     *
     * Passes the two QR columns to the shared reset helper so they are cleared
     * in the same transaction as the NFC payload — no nested transactions,
     * no duplicated NFC column list.
     */
    public function resetEverything(Card $card): void
    {
        $this->reset($card, [
            'qr_destination'   => null,
            'qr_configured_at' => null,
        ]);
    }

    /**
     * Clears the NFC payload (and any extra columns the caller supplies) in one
     * transaction, then removes the contact photo and the profile avatar from
     * disk and drops both loaded relations from the in-memory model.
     *
     * @param  array<string, mixed>  $extraColumns  Additional card columns to null out
     */
    private function reset(Card $card, array $extraColumns = []): void
    {
        $photoPath  = $card->contactProfile?->photo_path;
        $avatarPath = $card->profile?->avatar_path;

        DB::transaction(function () use ($card, $extraColumns) {
            $card->nfcLinks()->delete();

            if ($contact = $card->contactProfile) {
                $contact->delete();
            }

            if ($profile = $card->profile) {
                $profile->delete();
            }

            $card->forceFill(array_merge([
                'nfc_mode'          => NfcMode::Links,
                'nfc_configured_at' => null,
                'nfc_phone_number'  => null,
                'nfc_email_address' => null,
                'nfc_email_subject' => null,
                'nfc_email_body'    => null,
                'nfc_page_title'    => null,
                'nfc_page_subtitle' => null,
                'nfc_logo_path'     => null,
            ], $extraColumns))->save();
        });

        // Delete the photo only after the transaction commits. Filesystem
        // deletes do not roll back; a file removed while the profile row
        // survives would show a broken image on a live contact page. A file
        // that outlives the row is the worse-kept secret, so the storage helper
        // logs it rather than letting it pass unnoticed.
        $this->photos->forget($photoPath, $card);
        $this->photos->forget($avatarPath, $card);

        // The in-memory model may still hold the profile we just deleted.
        $card->unsetRelation('contactProfile');
        $card->unsetRelation('profile');
    }
}
