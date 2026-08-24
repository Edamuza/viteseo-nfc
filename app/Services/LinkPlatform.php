<?php

namespace App\Services;

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
     * "x.com/its_uriel26" rather than the full URL: the panel has to stay
     * readable in one line on a phone.
     */
    public static function displayUrl(string $url): string
    {
        $trimmed = trim($url);
        $short   = preg_replace('#^https?://#i', '', $trimmed) ?? $trimmed;
        $short   = preg_replace('#^www\.#i', '', $short) ?? $short;

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
