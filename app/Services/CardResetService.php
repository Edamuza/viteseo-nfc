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
     * database transaction, then deletes every publicly served image the reset
     * orphaned - the contact photo, the profile avatar and the landing page logo
     * - and unsets both in-memory relations. File deletion is deliberately
     * post-commit for both entry points:
     * filesystem deletes do not roll back, and a file removed while the profile
     * row survives would show a broken image on a live contact page.
     *
     * Every column here that names a file is paired with a forget(). An unpaired
     * one is not a tidy-up problem: the file stays fetchable at a guessable
     * /storage/... URL after a reset the admin was told succeeded, which on a
     * re-issued card means the previous holder's image served to its new owner.
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
     * Deletes only the Profile-mode payload: the profile row and its avatar.
     *
     * The admin panel's "Delete profile details" button, the counterpart of the
     * Contact-mode delete. It lives here rather than in the controller so that
     * what a profile owns on disk is stated once — see takeProfile(), which
     * both this and the reset path go through. Without it, clearing profile
     * data meant "Reset entire card", which also destroys the QR destination
     * the printed card still points at.
     *
     * Profile audit snapshots are part of the previous holder's payload too:
     * they can contain their text, links, and retained avatar paths. The row
     * and history are removed atomically, then every referenced image is
     * removed after commit.
     */
    public function deleteProfile(Card $card): void
    {
        $avatarPaths = DB::transaction(function () use ($card): array {
            $lockedCard = Card::query()->whereKey($card->id)->lockForUpdate()->firstOrFail();

            return array_merge(
                [$this->takeProfile($lockedCard)],
                $this->takeProfileHistory($lockedCard)
            );
        });

        foreach (array_unique(array_filter($avatarPaths)) as $avatarPath) {
            $this->photos->forget($avatarPath, $card);
        }

        $card->unsetRelation('profile');
        $card->unsetRelation('profileEdits');
    }

    /**
     * Deletes the profile row and hands back the avatar that is now orphaned,
     * for the caller to remove from disk once it is safe to do so.
     *
     * The return value is what lets the reset path run this inside its
     * transaction while still deleting the file only after the commit.
     */
    private function takeProfile(Card $card): ?string
    {
        if (! $profile = $card->profile) {
            return null;
        }

        $avatarPath = $profile->avatar_path;
        $profile->delete();

        return $avatarPath;
    }

    /**
     * Deletes the client-edit audit history and returns every avatar retained
     * solely because a snapshot can restore it.
     *
     * @return array<int, string>
     */
    private function takeProfileHistory(Card $card): array
    {
        $paths = $card->profileEdits()
            ->get()
            ->map(fn ($edit): ?string => data_get($edit->snapshot, 'profile.avatar_path'))
            ->filter(fn ($path): bool => is_string($path) && $path !== '')
            ->values()
            ->all();

        $card->profileEdits()->delete();

        return $paths;
    }

    /**
     * Clears the NFC payload (and any extra columns the caller supplies) in one
     * transaction, then removes the contact photo, the profile avatar and the
     * landing page logo from disk and drops both loaded relations from the
     * in-memory model.
     *
     * The two paths that survive the transaction are read before it: the logo
     * lives in a card column this method is about to null, so reading it
     * afterwards would always find the null it just wrote.
     *
     * @param  array<string, mixed>  $extraColumns  Additional card columns to null out
     */
    private function reset(Card $card, array $extraColumns = []): void
    {
        $photoPath  = null;
        $logoPath   = null;
        $avatarPath = null;
        $historicAvatarPaths = [];

        DB::transaction(function () use ($card, $extraColumns, &$photoPath, &$logoPath, &$avatarPath, &$historicAvatarPaths) {
            $lockedCard = Card::query()->whereKey($card->id)->lockForUpdate()->firstOrFail();
            $photoPath = $lockedCard->contactProfile?->photo_path;
            $logoPath = $lockedCard->nfc_logo_path;
            $lockedCard->nfcLinks()->delete();

            if ($contact = $lockedCard->contactProfile) {
                $contact->delete();
            }

            $avatarPath = $this->takeProfile($lockedCard);
            $historicAvatarPaths = $this->takeProfileHistory($lockedCard);

            $lockedCard->forceFill(array_merge([
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

        $card->refresh();

        // Delete the files only after the transaction commits. Filesystem
        // deletes do not roll back; a file removed while the profile row
        // survives would show a broken image on a live contact page. A file
        // that outlives the row is the worse-kept secret, so the storage helper
        // logs it rather than letting it pass unnoticed.
        $this->photos->forget($photoPath, $card);
        $this->photos->forget($avatarPath, $card);
        $this->photos->forget($logoPath, $card);
        foreach (array_unique($historicAvatarPaths) as $historicAvatarPath) {
            if ($historicAvatarPath !== $avatarPath) {
                $this->photos->forget($historicAvatarPath, $card);
            }
        }

        // The in-memory model may still hold the profile we just deleted.
        $card->unsetRelation('contactProfile');
        $card->unsetRelation('profile');
        $card->unsetRelation('profileEdits');
    }
}
