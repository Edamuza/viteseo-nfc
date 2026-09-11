<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class PlatformIconTest extends TestCase
{
    private function render(string $platform): string
    {
        return Blade::render('<x-platform-icon :platform="$platform" />', ['platform' => $platform]);
    }

    /** @return array<string, array{string}> */
    public static function platforms(): array
    {
        return [
            'x'         => ['x'],
            'linkedin'  => ['linkedin'],
            'tiktok'    => ['tiktok'],
            'github'    => ['github'],
            'facebook'  => ['facebook'],
            'instagram' => ['instagram'],
            'youtube'   => ['youtube'],
            'google'    => ['google'],
            'web'       => ['web'],
        ];
    }

    /**
     * platforms() is written out by hand, so a logo added to the dropdown
     * without being added here would skip both checks below - and could ship
     * as a copy of the globe. Pinned to the dropdown itself.
     */
    public function test_the_fixtures_cover_every_logo_the_dropdown_offers(): void
    {
        $offered = array_values(array_diff(array_keys(\App\Services\LinkPlatform::iconChoices()), [\App\Services\LinkPlatform::NONE]));
        $covered = array_map(fn (array $row) => $row[0], array_values(self::platforms()));
        sort($offered);
        sort($covered);

        $this->assertSame($offered, $covered);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('platforms')]
    public function test_every_platform_renders_one_svg(string $platform): void
    {
        $html = $this->render($platform);

        $this->assertSame(1, substr_count($html, '<svg'), 'exactly one mark per platform');
        $this->assertStringContainsString('viewBox="0 0 24 24"', $html);
    }

    public function test_each_platform_renders_a_distinct_mark(): void
    {
        $marks = [];

        foreach (array_map(fn (array $row) => $row[0], self::platforms()) as $platform) {
            $marks[$platform] = $this->render($platform);
        }

        $this->assertCount(
            count($marks),
            array_unique($marks),
            'two platforms render the same mark: a visitor cannot tell those rows apart'
        );
    }

    public function test_an_unknown_platform_falls_back_to_the_web_mark(): void
    {
        $this->assertSame($this->render('web'), $this->render('nonsense'));
    }
}
