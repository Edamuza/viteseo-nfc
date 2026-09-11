<?php

namespace App\Services;

use App\Rules\SafeExternalUrl;

/**
 * Maps a link URL to a platform key, its label, and a short display form.
 *
 * Pure and static on purpose: no database, no network, no state. The profile
 * page calls it once per link on every render, and a service that can fail or
 * block would put a third-party dependency in the path of a page whose whole
 * job is to load instantly on a phone.
 */
class LinkPlatform
{
    /** platform key => the hosts that mean it */
    private const HOSTS = [
        'x'         => ['x.com', 'twitter.com'],
        'linkedin'  => ['linkedin.com'],
        'tiktok'    => ['tiktok.com'],
        'github'    => ['github.com'],
        'facebook'  => ['facebook.com', 'fb.com'],
        'instagram' => ['instagram.com'],
        'youtube'   => ['youtube.com', 'youtu.be'],
        // One mark for everything Google a business links to: reviews, Maps,
        // the Business Profile. Only hosts Google alone serves: g.page and g.co
        // redirect to Google properties only, and maps.app.goo.gl to Maps. The
        // bare goo.gl is deliberately absent - it was a public shortener, so
        // any site at all can sit behind one, and it must not wear this mark.
        'google'    => ['google.com', 'g.page', 'g.co', 'maps.app.goo.gl'],
    ];

    private const LABELS = [
        'x'         => 'X',
        'linkedin'  => 'LinkedIn',
        'tiktok'    => 'TikTok',
        'github'    => 'GitHub',
        'facebook'  => 'Facebook',
        'instagram' => 'Instagram',
        'youtube'   => 'YouTube',
        'google'    => 'Google',
        'web'       => 'Website',
    ];

    /**
     * The platform key whose mark and label this URL has earned.
     *
     * A brand mark is a trust signal, so only a URL the visitor can actually
     * follow may earn one. parse_url reports a host of x.com for
     * "javascript://x.com/%0aalert(1)", so without the safeHref gate the row
     * would wear the X mark and read "X" while leading nowhere real — the same
     * borrowing the suffix anchoring below exists to stop, arriving through
     * the scheme instead of the host.
     */
    public static function forUrl(string $url): string
    {
        if (self::safeHref($url) === '#') {
            return 'web';
        }

        $host = self::host($url);

        if ($host === '') {
            return 'web';
        }

        foreach (self::HOSTS as $key => $needles) {
            foreach ($needles as $needle) {
                // Suffix-anchored, never str_contains. A contains check would
                // hand the LinkedIn mark to linkedin.com.attacker.tld, letting
                // a hostile link wear a trusted brand.
                if ($host === $needle || str_ends_with($host, '.'.$needle)) {
                    return $key;
                }
            }
        }

        return 'web';
    }

    /**
     * platform key => hosts, for the live setup preview.
     *
     * The preview has to guess the same icon "Auto" will resolve to once the
     * form is saved, and it runs in the browser. Handing it this table keeps
     * one list in one place; a second copy transcribed into JavaScript would
     * drift the moment a platform is added here.
     *
     * @return array<string, array<int, string>>
     */
    public static function hostMap(): array
    {
        return self::HOSTS;
    }

    public static function label(string $key): string
    {
        return self::LABELS[$key] ?? self::LABELS['web'];
    }

    /** A link whose author chose to show no logo at all. */
    public const NONE = 'none';

    /**
     * key => label for the logo dropdown on every add-link form, in display
     * order: the plain globe first (the answer for a portfolio or company
     * site), then the brand marks, then label-only.
     *
     * @return array<string, string>
     */
    public static function iconChoices(): array
    {
        $choices = ['web' => self::LABELS['web'].' (globe)'];

        foreach (array_keys(self::HOSTS) as $key) {
            // A lone "X" in a dropdown reads like a stray character, not a
            // platform. Only the choice label carries the clarification —
            // rendered rows keep the plain brand name from LABELS.
            $choices[$key] = $key === 'x' ? 'X (Twitter)' : self::LABELS[$key];
        }

        $choices[self::NONE] = 'No logo';

        return $choices;
    }

    /**
     * The icon key a link renders with: the author's explicit choice when one
     * was made, URL detection otherwise (which is also what every link stored
     * before the dropdown existed gets).
     */
    public static function iconFor(?string $chosen, string $url): string
    {
        if (is_string($chosen) && ($chosen === self::NONE || isset(self::LABELS[$chosen]))) {
            return $chosen;
        }

        return self::forUrl($url);
    }

    /**
     * The URL the visitor may safely tap, or "#".
     *
     * Escaping the attribute stops breakout but says nothing about the scheme:
     * a stored "javascript:" URL is a live script the moment the row is
     * tapped. SafeExternalUrl guards every write path today, so this is the
     * read-side backstop for a row written before that rule existed or by a
     * path that forgets it. Its allow-list is reused rather than copied,
     * because two lists that must agree eventually do not.
     *
     * Anything without an explicit http(s) scheme fails closed, including a
     * scheme-relative "//evil.tld" and input parse_url cannot read at all.
     *
     * A host is required as well as a scheme. Browsers treat "\" as "/" after
     * a special scheme, so "https:/\/\x.com@evil.tld/pay" navigates to
     * evil.tld while parse_url reports a scheme and no host — a live link
     * whose authority nothing here ever saw. Demanding both matches
     * SafeExternalUrl, which already requires scheme and host, so this can
     * never reject something a validated write produced.
     */
    public static function safeHref(string $url): string
    {
        $trimmed = trim($url);
        $parts   = parse_url($trimmed);

        if (! is_array($parts)) {
            return '#';
        }

        $scheme = $parts['scheme'] ?? null;

        return is_string($scheme)
            && in_array(strtolower($scheme), SafeExternalUrl::ALLOWED_SCHEMES, true)
            && (string) ($parts['host'] ?? '') !== ''
                ? $trimmed
                : '#';
    }

    /**
     * "x.com/its_uriel26" rather than the full URL: the panel has to stay
     * readable in one line on a phone.
     *
     * Rebuilt from parse_url's components rather than stripped with a prefix
     * regex. A strip only removes what it recognises at the front and leaves
     * the rest, so "https://x.com@attacker.tld/pay" would read as though it
     * led to x.com — and this line is the most-read thing in the panel.
     *
     * Only a URL safeHref would let through earns that short, host-leading
     * form; everything else is returned verbatim, scheme included. The short
     * form is an implicit claim that the row leads somewhere, and a dead row
     * must not be able to make it. That gate is also what makes "only the real
     * authority can lead" true rather than aspirational: safeHref guarantees a
     * host, so there is no fallback path where an unparsed authority survives.
     *
     * The query is kept: it frequently *is* the destination (youtube.com/watch
     * ?v=... is a different video per value). The fragment is dropped when
     * there is a real path, because there it is an in-page anchor that cannot
     * change which resource is served — but kept when the path is empty or
     * "/", because a hash-routed app puts its whole route there and
     * "example.com/#/dashboard" and "example.com/#/settings" would otherwise
     * render as two byte-identical rows leading to different places.
     *
     * Keeping either one does let an author pad the tail with brand-shaped
     * text: "attacker.tld/?x.com=verified" always could, and pointing a
     * fragment at your own root ("attacker.tld/#x.com/verified") gets the same
     * effect. Neither is a new class of problem, because the host still leads
     * in every case — which is the part that carries the trust.
     *
     * Known limits, all inherent to showing a hostname at all and all
     * mitigated by the row still reading "Website" under a globe rather than
     * under a borrowed brand:
     *
     *  - "https://x.com.attacker.tld/pay" displays as "x.com.attacker.tld/pay".
     *    The brand is genuinely in the host; only the suffix anchoring in
     *    forUrl() stops it also collecting the mark.
     *  - A unicode homograph ("аpple.com" with a Cyrillic а) renders as the
     *    lookalike it is. Not reachable today, since SafeExternalUrl restricts
     *    stored hosts to [a-zA-Z0-9.-], so only punycode can be written.
     */
    public static function displayUrl(string $url): string
    {
        $trimmed = trim($url);

        if (self::safeHref($trimmed) === '#') {
            return $trimmed;
        }

        // safeHref guarantees a parseable http(s) URL with a non-empty host.
        $parts = (array) parse_url($trimmed);
        $short = preg_replace('#^www\.#i', '', strtolower((string) ($parts['host'] ?? ''))) ?? '';

        if (isset($parts['port'])) {
            $short .= ':'.$parts['port'];
        }

        // The path keeps its case; only the host is case-insensitive.
        $path   = (string) ($parts['path'] ?? '');
        $short .= $path;

        if ((string) ($parts['query'] ?? '') !== '') {
            $short .= '?'.$parts['query'];
        }

        $short = rtrim($short, '/');

        if ((string) ($parts['fragment'] ?? '') !== '' && ($path === '' || $path === '/')) {
            $short .= '#'.$parts['fragment'];
        }

        // parse_url replaces C1 bytes (0x80-0x9F) in the host with "_", which
        // shreds any UTF-8 character whose encoding contains one and leaves a
        // lone lead byte behind. htmlspecialchars — what Blade's e() calls —
        // returns "" for invalid UTF-8, so the row would render an icon and a
        // label with no URL at all. The untouched input is still valid.
        //
        // This branch returns the input *with* its scheme, unlike every other
        // row. Do not "tidy" that up: the scheme is what keeps a borrowed brand
        // off the first character. Stripping it would turn
        // "https://x.com@х.evil.tld/pay" into "x.com@х.evil.tld/pay" and undo
        // the whole reason this method rebuilds from components. Only punycode
        // can be stored today, so nothing reaches here; whichever increment
        // adds IDN support has to strip the userinfo here instead.
        return mb_check_encoding($short, 'UTF-8') ? $short : $trimmed;
    }

    /**
     * The host, lowercased, with a leading www. removed. parse_url() is what
     * makes the userinfo and path cases safe: "https://x.com@attacker.tld/"
     * has a host of attacker.tld, and a brand name inside a path or query is
     * never part of the host at all.
     */
    private static function host(string $url): string
    {
        $host = parse_url(trim($url), PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return '';
        }

        return preg_replace('#^www\.#i', '', strtolower($host)) ?? '';
    }
}
