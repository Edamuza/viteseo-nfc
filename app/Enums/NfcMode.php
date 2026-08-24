<?php

namespace App\Enums;

/**
 * What a card's permanent NFC URL resolves to.
 *
 * The physical tag ALWAYS holds https://nfc.viteseo.ph/n/{public_id} regardless
 * of mode - that is the whole point. Switching mode changes what that URL does,
 * never the tag itself.
 */
enum NfcMode: string
{
    case Links   = 'links';
    case Contact = 'contact';
    case Phone   = 'phone';
    case Email   = 'email';
    case Profile = 'profile';

    public function label(): string
    {
        return match ($this) {
            self::Links   => 'Links / Multi-Link',
            self::Contact => 'Digital Contact Card',
            self::Phone   => 'Phone / Call',
            self::Email   => 'Email',
            self::Profile => 'Profile Page',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Links   => 'One link redirects; several show a link menu.',
            self::Contact => 'Shows a digital business card with a Save to Contacts button.',
            self::Phone   => 'Shows a single Call button.',
            self::Email   => 'Shows a single Send Email button.',
            self::Profile => 'Shows a rich profile page with avatar, bio and a list of linked accounts.',
        };
    }

    /** @return array<int, self> */
    public static function all(): array
    {
        return self::cases();
    }
}
