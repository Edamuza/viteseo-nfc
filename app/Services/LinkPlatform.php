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
    ];

    private const LABELS = [
        'x'         => 'X',
        'linkedin'  => 'LinkedIn',
        'tiktok'    => 'TikTok',
        'github'    => 'GitHub',
        'facebook'  => 'Facebook',
        'instagram' => 'Instagram',
        'youtube'   => 'YouTube',
        'web'       => 'Website',
    ];

    public static function forUrl(string $url): string
    {
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

    public static function label(string $key): string
    {
        return self::LABELS[$key] ?? self::LABELS['web'];
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
     */
    public static function safeHref(string $url): string
    {
        $trimmed = trim($url);
        $scheme  = parse_url($trimmed, PHP_URL_SCHEME);

        return is_string($scheme) && in_array(strtolower($scheme), SafeExternalUrl::ALLOWED_SCHEMES, true)
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
     * led to x.com — and this line is the most-read thing in the panel. Taking
     * the host parse_url reports means only the real authority can lead.
     *
     * The query is kept: it frequently *is* the destination (youtube.com/watch
     * ?v=... is a different video per value). The fragment is dropped: it never
     * reaches a server, so it cannot change which origin answers, and it is the
     * one component an author can stuff with brand-shaped padding for free. The
     * href keeps the whole URL either way, so nothing is lost by following it.
     */
    public static function displayUrl(string $url): string
    {
        $trimmed = trim($url);
        $parts   = parse_url($trimmed);
        $host    = is_array($parts) ? (string) ($parts['host'] ?? '') : '';

        if ($host === '') {
            // No authority to misread. Fall back to the cosmetic strip so a
            // scheme-less or unparseable value still reads as it was typed.
            $short = preg_replace('#^https?://#i', '', $trimmed) ?? $trimmed;

            return rtrim(preg_replace('#^www\.#i', '', $short) ?? $short, '/');
        }

        $short = preg_replace('#^www\.#i', '', strtolower($host)) ?? '';

        if (isset($parts['port'])) {
            $short .= ':'.$parts['port'];
        }

        // The path keeps its case; only the host is case-insensitive.
        $short .= (string) ($parts['path'] ?? '');

        if ((string) ($parts['query'] ?? '') !== '') {
            $short .= '?'.$parts['query'];
        }

        return rtrim($short, '/');
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
