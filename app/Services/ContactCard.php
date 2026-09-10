<?php

namespace App\Services;

use App\Models\Card;
use App\Models\NfcContactProfile;
use App\Models\NfcProfile;

/**
 * What a Contact-mode card actually hands a visitor's phone.
 *
 * The card's own contact details, with every group the owner left blank
 * filled from the card's profile page. Nothing is copied: the result is an
 * unsaved model, built fresh on every tap, so editing the profile page
 * changes the contact card too - an owner who set up a profile and then
 * switched to Contact starts with a working card instead of an empty one.
 *
 * Filled a GROUP at a time, never a field at a time. A contact card with a
 * work number of its own keeps exactly that number; the profile's phone is
 * only used when the card has no phone at all. Mixing typed numbers with
 * another source's would hand a stranger a contact that neither screen ever
 * showed its owner.
 */
final class ContactCard
{
    /**
     * @param  array<int, string>  $urls  links the card serves, used only
     *                                    when the details carry no web address
     */
    private function __construct(
        public readonly NfcContactProfile $details,
        public readonly array $urls,
    ) {}

    /** Null when there is nothing worth saving to a phone. */
    public static function for(Card $card): ?self
    {
        $contact = $card->contactProfile;
        $details = $contact ? $contact->replicate() : new NfcContactProfile();

        self::fillBlanks($details, self::fromProfile($card->profile));

        if (! $details->hasName()) {
            return null;
        }

        $hasOwnUrl = (string) $details->website !== '' || $details->socials() !== [];

        $urls = $hasOwnUrl ? [] : $card->activeNfcLinks()->pluck('url')
            ->map(fn ($url) => trim((string) $url))
            ->filter()
            ->values()
            ->all();

        return new self($details, $urls);
    }

    /**
     * What the profile page offers a contact card, keyed by the contact
     * column it would fill. Only non-empty values.
     *
     * Public because the setup form and the admin screen show the owner these
     * same values, and a second copy of this mapping would drift.
     *
     * @return array<string, string>
     */
    public static function fromProfile(?NfcProfile $profile): array
    {
        if (! $profile) {
            return [];
        }

        // The headline is written "Owner, Acme Inc." - the same split
        // profile.vcf has always made.
        [$title, $organisation] = array_map('trim', array_pad(explode(',', (string) $profile->headline, 2), 2, ''));

        return array_filter([
            'first_name'     => trim((string) $profile->display_name),
            'job_title'      => $title,
            'company'        => $organisation,
            'mobile_phone'   => trim((string) $profile->phone),
            'personal_email' => trim((string) $profile->email),
            'notes'          => trim((string) $profile->tagline),
            'photo_path'     => (string) $profile->avatar_path,
        ], fn (string $value) => $value !== '');
    }

    /** @param  array<string, string>  $from */
    private static function fillBlanks(NfcContactProfile $details, array $from): void
    {
        $blank = [
            'first_name'     => $details->displayName() === '',
            'job_title'      => (string) $details->job_title === '',
            'company'        => (string) $details->company === '',
            'mobile_phone'   => $details->phones() === [],
            'personal_email' => $details->emails() === [],
            'notes'          => (string) $details->notes === '',
            'photo_path'     => (string) $details->photo_path === '',
        ];

        foreach ($from as $column => $value) {
            if ($blank[$column]) {
                $details->{$column} = $value;
            }
        }
    }
}
