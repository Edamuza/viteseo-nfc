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

    /**
     * The name a card owner sees.
     *
     * Written for someone who has never built a web page: no slashes, no
     * "multi-link", no "digital". The admin screens use these same names on
     * purpose - one vocabulary means an owner on the phone and an admin on
     * the dashboard are talking about the same thing.
     */
    public function label(): string
    {
        return match ($this) {
            self::Links   => 'My links',
            self::Contact => 'Contact card',
            self::Phone   => 'My phone number',
            self::Email   => 'My email address',
            self::Profile => 'Full profile page',
        };
    }

    /**
     * One plain sentence about what the other person sees after the tap.
     *
     * Always phrased from the visitor's side ("they see..."), because that is
     * the question an owner is actually asking while choosing.
     */
    public function description(): string
    {
        return match ($this) {
            self::Links   => 'They see a list of buttons - your website, Facebook, shop, anything.',
            self::Contact => 'They see your details and a button that saves you into their phone.',
            self::Phone   => 'They see one big Call button with your number.',
            self::Email   => 'They see one big button that starts an email to you.',
            self::Profile => 'They see your photo, a short line about you, and all your links.',
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

    /**
     * Modes a customer may pick when setting up an unconfigured card
     * themselves. Email is deliberately absent: it is hidden from both the
     * public chooser and the admin mode selector for now (existing Email
     * cards keep working; the mode is hidden, not removed).
     *
     * @return array<int, self>
     */
    public static function selfSetup(): array
    {
        return [self::Links, self::Contact, self::Phone, self::Profile];
    }
}
