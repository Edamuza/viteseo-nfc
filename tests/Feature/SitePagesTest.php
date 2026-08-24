<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\SitePage;
use App\Models\User;
use App\Services\CardGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SitePagesTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'name' => 'Admin',
            'email' => 'site-pages@example.com',
            'password' => Hash::make('correct-horse-battery-1'),
        ]);
    }

    private function profileCard(): Card
    {
        $card = app(CardGenerationService::class)->generate(1)->first();
        $card->profile()->save($card->profile()->make(['display_name' => 'Uriel Papa']));
        $card->forceFill(['nfc_mode' => 'profile'])->save();

        return $card->fresh();
    }

    public function test_migration_seeds_exactly_the_three_global_pages(): void
    {
        $this->assertSame(['about', 'privacy', 'terms'], SitePage::query()->orderBy('key')->pluck('key')->all());
        $this->assertSame(3, SitePage::query()->count());
        $this->assertSame(0, SitePage::query()->where('title', '')->count());
        $this->assertSame(0, SitePage::query()->where('body', '')->count());
    }

    public function test_page_keys_are_immutable_and_bodies_split_on_blank_lines(): void
    {
        $page = SitePage::query()->where('key', 'about')->firstOrFail();
        $page->fill([
            'key' => 'renamed',
            'title' => 'About these cards',
            'body' => "First paragraph.\n\nSecond paragraph.\n   \nThird paragraph.",
        ])->save();

        $page->refresh();

        $this->assertSame('about', $page->key);
        $this->assertSame(['First paragraph.', 'Second paragraph.', 'Third paragraph.'], $page->paragraphs());
        $this->assertSame('key', $page->getRouteKeyName());
    }

    public function test_profile_footer_renders_all_three_seeded_pages_as_target_modals(): void
    {
        $card = $this->profileCard();
        $body = $this->get('/n/'.$card->public_id)->assertOk()->getContent();

        foreach ([
            'about' => 'What is this?',
            'terms' => 'Terms of Service',
            'privacy' => 'Privacy Policy',
        ] as $key => $label) {
            $page = SitePage::query()->where('key', $key)->firstOrFail();
            $this->assertStringContainsString('href="#'.$key.'"', $body);
            $this->assertStringContainsString('class="modal" id="'.$key.'"', $body);
            $this->assertStringContainsString($label, $body);
            $this->assertStringContainsString(e($page->paragraphs()[0]), $body);
        }
    }

    public function test_site_page_body_is_escaped_and_blank_lines_become_paragraphs(): void
    {
        SitePage::query()->where('key', 'about')->firstOrFail()->update([
            'body' => "Safe first paragraph.\n\n<script>alert(document.domain)</script>\n\nSafe third paragraph.",
        ]);

        $body = $this->get('/n/'.$this->profileCard()->public_id)->assertOk()->getContent();

        $this->assertStringNotContainsString('<script>alert(document.domain)</script>', $body);
        $this->assertStringContainsString('&lt;script&gt;alert(document.domain)&lt;/script&gt;', $body);
        $this->assertStringContainsString('<p>Safe first paragraph.</p>', $body);
        $this->assertStringContainsString('<p>Safe third paragraph.</p>', $body);
    }

    public function test_site_page_admin_routes_require_authentication(): void
    {
        $this->get('/admin/site-pages')->assertRedirect('/admin/login');
        $this->patch('/admin/site-pages/about', [
            'title' => 'Changed',
            'body' => 'Changed',
        ])->assertRedirect('/admin/login');
    }

    public function test_admin_can_edit_title_and_body_but_cannot_rename_the_page_key(): void
    {
        $response = $this->actingAs($this->admin())->patch('/admin/site-pages/about', [
            'key' => 'hijacked',
            'title' => 'About ViteSEO NFC cards',
            'body' => "Updated paragraph one.\n\nUpdated paragraph two.",
        ]);

        $response->assertRedirect('/admin/site-pages')->assertSessionHas('status');

        $page = SitePage::query()->where('key', 'about')->firstOrFail();
        $this->assertSame('About ViteSEO NFC cards', $page->title);
        $this->assertSame("Updated paragraph one.\n\nUpdated paragraph two.", $page->body);
        $this->assertFalse(SitePage::query()->where('key', 'hijacked')->exists());
    }

    public function test_admin_editor_validates_title_and_is_linked_from_the_navigation(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->patch('/admin/site-pages/about', [
            'title' => str_repeat('x', 121),
            'body' => 'Body',
        ])->assertSessionHasErrors('title');

        $this->actingAs($admin)->get('/admin/site-pages')
            ->assertOk()
            ->assertSee('Global profile pages')
            ->assertSee('name="body"', false)
            ->assertSee('Site Pages');
    }
}
