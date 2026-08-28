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
     * What the other person ends up looking at, in as few words as possible.
     *
     * Deliberately not a full sentence: every place this is shown now sits
     * next to a Preview button that answers the question properly, and the
     * paragraphs these used to be were the clutter owners complained about.
     */
    public function description(): string
    {
        return match ($this) {
            self::Links   => 'A list of link buttons.',
            self::Contact => 'Your details, saved to their phone.',
            self::Phone   => 'One Call button.',
            self::Email   => 'One Email button.',
            self::Profile => 'Photo, bio and all your links.',
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
     * Every mode in the order pickers show them, best first.
     *
     * Profile leads because it is the one that makes a card look like a
     * product; the enum's own case order stays as it is, because that order
     * is about the history of the column, not about what to recommend.
     *
     * @return array<int, self>
     */
    public static function ordered(): array
    {
        return [self::Profile, self::Links, self::Contact, self::Phone, self::Email];
    }

    /**
     * Kept out of every picker, the admin's included - without being removed.
     * A card already serving a hidden mode keeps working, keeps rendering,
     * and still shows its own option in the admin selector so the mode can be
     * seen and changed.
     */
    public function isHidden(): bool
    {
        return match ($this) {
            self::Email => true,
            // Phone is a contact card with one field filled in. Two choices
            // that do nearly the same thing is what made the list hard to
            // read, and the contact card wins: it can still be called.
            self::Phone => true,
            self::Links, self::Contact, self::Profile => false,
        };
    }

    /**
     * Modes a customer may pick when setting up a card themselves.
     *
     * @return array<int, self>
     */
    public static function selfSetup(): array
    {
        return array_values(array_filter(self::ordered(), fn (self $mode) => ! $mode->isHidden()));
    }
}
