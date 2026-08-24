<?php

namespace Tests\Unit;

use App\Rules\SafeExternalUrl;
use App\Services\LinkPlatform;
use PHPUnit\Framework\TestCase;

class LinkPlatformTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function hosts(): array
    {
        return [
            'x'                   => ['https://x.com/its_uriel26', 'x'],
            'twitter is still x'  => ['https://twitter.com/its_uriel26', 'x'],
            'linkedin'            => ['https://www.linkedin.com/in/uriel-papa', 'linkedin'],
            'regional linkedin'   => ['https://ph.linkedin.com/in/uriel-papa', 'linkedin'],
            'tiktok'              => ['https://www.tiktok.com/@its_uriel26', 'tiktok'],
            'github'              => ['https://github.com/urielpapa', 'github'],
            'facebook'            => ['https://www.facebook.com/uriel.papa', 'facebook'],
            'fb.com'              => ['https://fb.com/uriel.papa', 'facebook'],
            'instagram'           => ['https://instagram.com/its_uriel26', 'instagram'],
            'youtube'             => ['https://www.youtube.com/@vitese0', 'youtube'],
            'youtu.be'            => ['https://youtu.be/dQw4w9WgXcQ', 'youtube'],
            'anything else'       => ['https://viteseo.ph/services', 'web'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('hosts')]
    public function test_recognised_hosts_map_to_their_platform(string $url, string $expected): void
    {
        $this->assertSame($expected, LinkPlatform::forUrl($url));
    }

    /** @return array<string, array{string}> */
    public static function spoofedHosts(): array
    {
        return [
            'brand as a subdomain of an attacker' => ['https://linkedin.com.attacker.tld/in/uriel'],
            'brand inside a longer host'          => ['https://notlinkedin.com/in/uriel'],
            'brand in the path only'              => ['https://attacker.tld/linkedin.com/in/uriel'],
            'brand in the query only'             => ['https://attacker.tld/?ref=x.com'],
            'brand as userinfo'                   => ['https://x.com@attacker.tld/'],
        ];
    }

    /**
     * A hostile link must not be able to borrow a trusted brand's icon. The
     * icon is the only signal a visitor has about where a row leads, so this is
     * a spoofing test, not a formatting one.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('spoofedHosts')]
    public function test_a_spoofed_host_is_not_given_a_brand_icon(string $url): void
    {
        $this->assertSame('web', LinkPlatform::forUrl($url));
    }

    public function test_an_unparseable_url_is_web(): void
    {
        $this->assertSame('web', LinkPlatform::forUrl('not a url at all'));
        $this->assertSame('web', LinkPlatform::forUrl(''));
    }

    /** @return array<string, array{string, string}> */
    public static function labels(): array
    {
        return [
            'x'         => ['x', 'X'],
            'linkedin'  => ['linkedin', 'LinkedIn'],
            'tiktok'    => ['tiktok', 'TikTok'],
            'github'    => ['github', 'GitHub'],
            'facebook'  => ['facebook', 'Facebook'],
            'instagram' => ['instagram', 'Instagram'],
            'youtube'   => ['youtube', 'YouTube'],
            'web'       => ['web', 'Website'],
            'unknown'   => ['nonsense', 'Website'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('labels')]
    public function test_each_key_has_a_label(string $key, string $expected): void
    {
        $this->assertSame($expected, LinkPlatform::label($key));
    }

    /** @return array<string, array{string, string}> */
    public static function displayUrls(): array
    {
        return [
            'strips scheme and www'    => ['https://www.x.com/its_uriel26', 'x.com/its_uriel26'],
            'strips a trailing slash'  => ['https://github.com/urielpapa/', 'github.com/urielpapa'],
            'keeps a deep path'        => ['https://linkedin.com/in/uriel-papa-vitese01', 'linkedin.com/in/uriel-papa-vitese01'],
            'keeps a query'            => ['https://viteseo.ph/s?ref=card', 'viteseo.ph/s?ref=card'],
            'bare host'                => ['https://viteseo.ph', 'viteseo.ph'],
            'http too'                 => ['http://viteseo.ph/x', 'viteseo.ph/x'],
            'leaves junk alone'        => ['not a url at all', 'not a url at all'],

            // The authority is rebuilt from parse_url rather than stripped with
            // a prefix regex, so nothing before the real host can survive.
            'userinfo cannot lead'     => ['https://x.com@attacker.tld/pay', 'attacker.tld/pay'],
            'userinfo with a password' => ['https://x.com:tok@attacker.tld/pay', 'attacker.tld/pay'],
            'keeps a port'             => ['https://viteseo.ph:8443/x', 'viteseo.ph:8443/x'],
            'drops a fragment'         => ['https://viteseo.ph/s?ref=card#pricing', 'viteseo.ph/s?ref=card'],
            'lowercases the host only' => ['https://WWW.X.COM/Path', 'x.com/Path'],
            'scheme relative'          => ['//viteseo.ph/x', 'viteseo.ph/x'],
            'empty'                    => ['', ''],
        ];
    }

    /**
     * This is the panel's most-read line, so it has to name where the link
     * actually goes. A prefix strip cannot do that: it leaves everything
     * between the scheme and the host in place, so
     * "https://x.com@attacker.tld/pay" would read as though it led to x.com.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('displayUrls')]
    public function test_display_url_is_readable_on_a_phone(string $url, string $expected): void
    {
        $this->assertSame($expected, LinkPlatform::displayUrl($url));
    }

    /** @return array<string, array{string, string}> */
    public static function hrefs(): array
    {
        return [
            'https'                => ['https://viteseo.ph/x', 'https://viteseo.ph/x'],
            'http'                 => ['http://viteseo.ph/x', 'http://viteseo.ph/x'],
            'uppercase scheme'     => ['HTTPS://viteseo.ph/x', 'HTTPS://viteseo.ph/x'],
            'surrounding space'    => ['  https://viteseo.ph/x  ', 'https://viteseo.ph/x'],
            'javascript'           => ['javascript:alert(document.domain)', '#'],
            'uppercase javascript' => ['JAVASCRIPT:alert(1)', '#'],
            'data'                 => ['data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==', '#'],
            'scheme relative'      => ['//evil.tld/pay', '#'],
            'mailto'               => ['mailto:someone@example.com', '#'],
            'unparseable'          => ['not a url at all', '#'],
            'empty'                => ['', '#'],
        ];
    }

    /**
     * Escaping the href stops attribute breakout; it does nothing about the
     * scheme. A stored "javascript:" URL is still a live script the moment a
     * visitor taps the row, so the scheme is checked rather than assumed.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('hrefs')]
    public function test_only_http_urls_survive_as_an_href(string $url, string $expected): void
    {
        $this->assertSame($expected, LinkPlatform::safeHref($url));
    }

    /**
     * One allow-list, not two. SafeExternalUrl guards every write path today,
     * and safeHref is the read-side backstop for rows written before that rule
     * existed or by a path that forgets it; the two disagreeing would reopen
     * exactly the hole the backstop is there to close.
     */
    public function test_the_href_allow_list_is_the_one_the_write_path_enforces(): void
    {
        $this->assertSame(['http', 'https'], SafeExternalUrl::ALLOWED_SCHEMES);
    }
}
