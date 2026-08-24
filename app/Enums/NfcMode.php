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

    /**
     * Does this mode publish `nfc_links` to a visitor?
     *
     * Links mode serves them as buttons or a redirect; Profile mode serves the
     * same rows as its Accounts panel. The other three publish something else
     * entirely, so a link count next to them describes nothing a visitor can
     * reach — which is why the dashboard shows an em dash instead.
     *
     * An exhaustive match, not a default: a sixth mode must state its answer
     * here rather than inherit one. Silently inheriting "no" is exactly how the
     * dashboard came to print an em dash for a Profile card that was serving
     * two links.
     */
    public function publishesLinks(): bool
    {
        return match ($this) {
            self::Links, self::Profile => true,
            self::Contact, self::Phone, self::Email => false,
        };
    }

    /** @return array<int, self> */
    public static function all(): array
    {
        return self::cases();
    }
}
