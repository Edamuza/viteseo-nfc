<?php

namespace Tests\Unit;

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
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('displayUrls')]
    public function test_display_url_is_readable_on_a_phone(string $url, string $expected): void
    {
        $this->assertSame($expected, LinkPlatform::displayUrl($url));
    }
}
