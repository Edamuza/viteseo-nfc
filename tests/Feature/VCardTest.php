<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\NfcContactProfile;
use App\Services\CardGenerationService;
use App\Services\VCardBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VCardTest extends TestCase
{
    use RefreshDatabase;

    private function contactCard(array $attrs = []): Card
    {
        $card = app(CardGenerationService::class)->generate(1)->first();
        $card->contactProfile()->save($card->contactProfile()->make(array_merge([
            'first_name' => 'John', 'last_name' => 'Doe',
        ], $attrs)));
        $card->forceFill(['nfc_mode' => 'contact'])->save();

        return $card->fresh();
    }

    private function vcf(Card $card): string
    {
        return $this->get('/n/'.$card->public_id.'/contact.vcf')->assertOk()->getContent();
    }

    private function profileCard(array $attrs = []): Card
    {
        $card = app(CardGenerationService::class)->generate(1)->first();
        $card->profile()->save($card->profile()->make(array_merge([
            'display_name' => 'Uriel Papa',
        ], $attrs)));
        $card->forceFill(['nfc_mode' => 'profile'])->save();

        return $card->fresh();
    }

    private function profileVcf(Card $card): string
    {
        return $this->get('/n/'.$card->public_id.'/profile.vcf')->assertOk()->getContent();
    }

    // ── Endpoint ────────────────────────────────────────────────────────────

    public function test_vcard_endpoint_returns_a_downloadable_vcard(): void
    {
        $card = $this->contactCard();

        $r = $this->get('/n/'.$card->public_id.'/contact.vcf')->assertOk();

        $this->assertStringContainsString('text/vcard', (string) $r->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment; filename="John-Doe.vcf"', (string) $r->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', (string) $r->headers->get('Cache-Control'));
    }

    public function test_vcard_404s_when_the_card_is_not_in_contact_mode(): void
    {
        $card = $this->contactCard();
        $card->forceFill(['nfc_mode' => 'links'])->save();

        $this->get('/n/'.$card->public_id.'/contact.vcf')->assertNotFound();
    }

    public function test_vcard_404s_for_an_unknown_card(): void
    {
        $this->get('/n/NOSUCHID/contact.vcf')->assertNotFound();
    }

    public function test_profile_vcard_maps_identity_headline_links_and_tagline(): void
    {
        $card = $this->profileCard([
            'headline' => 'Chief Executive Officer, Vite SEO Digital Advertising OPC',
            'tagline'  => 'Say my name',
        ]);
        $card->nfcLinks()->createMany([
            ['title' => 'Site', 'url' => 'https://example.com', 'sort_order' => 0, 'is_active' => true],
            ['title' => 'LinkedIn', 'url' => 'https://linkedin.com/in/uriel', 'sort_order' => 1, 'is_active' => true],
            ['title' => 'Old', 'url' => 'https://old.example.com', 'sort_order' => 2, 'is_active' => false],
        ]);

        $response = $this->get('/n/'.$card->public_id.'/profile.vcf')
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $vcard = $response->getContent();

        $this->assertStringStartsWith("BEGIN:VCARD\r\nVERSION:3.0\r\n", $vcard);
        $this->assertStringContainsString("\r\nN:;Uriel Papa;;;\r\n", $vcard);
        $this->assertStringContainsString("\r\nFN:Uriel Papa\r\n", $vcard);
        $this->assertStringContainsString("\r\nTITLE:Chief Executive Officer\r\n", $vcard);
        $this->assertStringContainsString("\r\nORG:Vite SEO Digital Advertising OPC\r\n", $vcard);
        $this->assertSame(2, substr_count($vcard, "\r\nURL:"));
        $this->assertStringNotContainsString('old.example.com', $vcard);
        $this->assertStringContainsString("\r\nNOTE:Say my name\r\n", $vcard);
        $this->assertStringEndsWith("END:VCARD\r\n", $vcard);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertMatchesRegularExpression(
            '/^attachment; filename="profile-[A-Z0-9]+\.vcf"$/',
            (string) $response->headers->get('Content-Disposition')
        );
    }

    public function test_profile_vcard_headline_without_a_comma_has_title_but_no_org(): void
    {
        $vcard = $this->profileVcf($this->profileCard(['headline' => 'Founder']));

        $this->assertStringContainsString("\r\nTITLE:Founder\r\n", $vcard);
        $this->assertStringNotContainsString("\r\nORG:", $vcard);
    }

    public function test_profile_vcard_escapes_injection_characters(): void
    {
        $card = $this->profileCard([
            'display_name' => "Uriel, Papa;\r\nTEL:+199999999",
            'headline'     => "CEO;\r\nORG:Injected, Vite\\SEO",
            'tagline'      => "Hello\r\nEND:VCARD\r\nBEGIN:VCARD",
        ]);

        $vcard = $this->profileVcf($card);
        $lines = explode("\r\n", $vcard);

        $this->assertSame(1, count(array_filter($lines, fn ($line) => $line === 'BEGIN:VCARD')));
        $this->assertSame(1, count(array_filter($lines, fn ($line) => $line === 'END:VCARD')));
        $this->assertSame(0, count(array_filter($lines, fn ($line) => $line === 'TEL:+199999999')));
        $this->assertSame(0, count(array_filter($lines, fn ($line) => $line === 'ORG:Injected')));
        $this->assertStringContainsString('Uriel\\, Papa\\;\\nTEL:+199999999', $vcard);
        $this->assertStringContainsString('NOTE:Hello\\nEND:VCARD\\nBEGIN:VCARD', $vcard);
    }

    public function test_profile_vcard_rejects_other_modes_and_empty_profiles(): void
    {
        $linksCard = $this->profileCard();
        $linksCard->forceFill(['nfc_mode' => 'links'])->save();

        $empty = $this->profileCard(['display_name' => '']);

        $this->get('/n/'.$linksCard->public_id.'/profile.vcf')->assertNotFound();
        $this->get('/n/'.$empty->public_id.'/profile.vcf')->assertNotFound();
    }

    // ── Structure ───────────────────────────────────────────────────────────

    public function test_vcard_is_version_3_and_contains_fn_and_n(): void
    {
        $v = $this->vcf($this->contactCard(['middle_name' => 'Quincy', 'suffix' => 'Jr']));

        $this->assertStringStartsWith("BEGIN:VCARD\r\nVERSION:3.0\r\n", $v);
        $this->assertStringEndsWith("END:VCARD\r\n", $v);
        $this->assertStringContainsString("\r\nFN:John Quincy Doe Jr\r\n", $v);
        $this->assertStringContainsString("\r\nN:Doe;John;Quincy;;Jr\r\n", $v);
    }

    public function test_vcard_uses_crlf_line_endings_throughout(): void
    {
        $v = $this->vcf($this->contactCard(['company' => 'Acme']));

        // No bare LF anywhere.
        $this->assertSame(0, preg_match_all('/(?<!\r)\n/', $v));
    }

    public function test_vcard_contains_company_title_phones_emails_url_address_birthday_notes(): void
    {
        $v = $this->vcf($this->contactCard([
            'company' => 'Acme Ltd', 'job_title' => 'Director',
            'mobile_phone' => '+639171234567', 'work_phone' => '+63212345678',
            'landline' => '+6329876543', 'other_phone' => '+63212223333',
            'work_email' => 'work@example.com', 'personal_email' => 'me@example.com',
            'website' => 'https://example.com',
            'street' => '12 Main St', 'city' => 'Cebu', 'state' => 'Cebu',
            'postal_code' => '6000', 'country' => 'Philippines',
            'birthday' => '1985-04-17',
            'notes' => 'Met at the trade show.',
        ]));

        $this->assertStringContainsString('ORG:Acme Ltd', $v);
        $this->assertStringContainsString('TITLE:Director', $v);
        $this->assertStringContainsString('TEL;TYPE=CELL,VOICE:+639171234567', $v);
        $this->assertStringContainsString('TEL;TYPE=WORK,VOICE:+63212345678', $v);
        $this->assertStringContainsString('TEL;TYPE=HOME,VOICE:+6329876543', $v);
        $this->assertStringContainsString('EMAIL;TYPE=INTERNET,WORK:work@example.com', $v);
        $this->assertStringContainsString('EMAIL;TYPE=INTERNET,HOME:me@example.com', $v);
        $this->assertStringContainsString('URL:https://example.com', $v);
        $this->assertStringContainsString('ADR;TYPE=WORK:;;12 Main St;Cebu;Cebu;6000;Philippines', $v);
        $this->assertStringContainsString('BDAY:1985-04-17', $v);
        $this->assertStringContainsString('NOTE:Met at the trade show.', $v);
    }

    public function test_absent_fields_produce_no_empty_lines(): void
    {
        $v = $this->vcf($this->contactCard());

        foreach (['ORG:', 'TITLE:', 'TEL', 'EMAIL', 'URL', 'ADR', 'BDAY', 'NOTE'] as $prop) {
            $this->assertStringNotContainsString("\r\n{$prop}", $v, "{$prop} should be absent");
        }
    }

    // ── Escaping / injection ────────────────────────────────────────────────

    public function test_commas_semicolons_and_backslashes_are_escaped(): void
    {
        $v = $this->vcf($this->contactCard([
            'company' => 'Acme, Inc; Ltd\\Group',
            'notes'   => 'A, B; C\\D',
        ]));

        $this->assertStringContainsString('ORG:Acme\\, Inc\; Ltd\\\\Group', $v);
        $this->assertStringContainsString('NOTE:A\\, B\; C\\\\D', $v);
    }

    public function test_newlines_in_notes_become_escaped_literals_not_new_lines(): void
    {
        $v = $this->vcf($this->contactCard(['notes' => "First line\nSecond line"]));

        $this->assertStringContainsString('NOTE:First line\\nSecond line', $v);
        $this->assertStringNotContainsString("NOTE:First line\r\nSecond", $v);
    }

    public function test_crlf_injection_cannot_forge_a_vcard_property(): void
    {
        $v = $this->vcf($this->contactCard([
            'company' => "Acme\r\nTEL;TYPE=CELL:+1999999999",
            'notes'   => "x\r\nEND:VCARD\r\nBEGIN:VCARD\r\nFN:Injected",
        ]));

        // What matters is LINES, not substrings: the hostile text still appears
        // inside the NOTE value as an escaped literal, which is exactly correct -
        // it cannot terminate the line, so it can never become a property.
        $lines = explode("\r\n", $v);

        $this->assertSame(1, count(array_filter($lines, fn ($l) => $l === 'BEGIN:VCARD')));
        $this->assertSame(1, count(array_filter($lines, fn ($l) => $l === 'END:VCARD')));
        $this->assertSame(0, count(array_filter($lines, fn ($l) => str_starts_with($l, 'TEL;TYPE=CELL:+1999999999'))));
        $this->assertSame(0, count(array_filter($lines, fn ($l) => $l === 'FN:Injected')));

        // Every line must be a real vCard property (NAME[;params]:value).
        foreach (array_filter($lines) as $line) {
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9\-]+(;[^:]*)?:/', $line, "Malformed vCard line: {$line}");
        }

        // And the escaped forms ARE present, proving escaping happened.
        $this->assertStringContainsString('\\nEND:VCARD\\nBEGIN:VCARD', $v);
    }

    public function test_header_injection_via_the_filename_is_impossible(): void
    {
        $card = $this->contactCard([
            'first_name' => "John\r\nX-Injected: 1",
            'last_name'  => '"; evil',
        ]);

        $r = $this->get('/n/'.$card->public_id.'/contact.vcf')->assertOk();
        $disposition = (string) $r->headers->get('Content-Disposition');

        $this->assertNull($r->headers->get('X-Injected'));
        $this->assertStringNotContainsString("\r", $disposition);
        $this->assertStringNotContainsString("\n", $disposition);
        // Slug is alphanumeric+hyphen only.
        $this->assertMatchesRegularExpression('/^attachment; filename="[A-Za-z0-9\-]+\.vcf"$/', $disposition);
    }

    public function test_socials_are_written_twice_so_they_survive_platforms_ignoring_extensions(): void
    {
        $v = $this->vcf($this->contactCard([
            'linkedin_url'  => 'https://linkedin.com/in/jdoe',
            'instagram_url' => 'https://instagram.com/jdoe',
        ]));

        // Apple extension...
        $this->assertStringContainsString('X-SOCIALPROFILE;TYPE=LinkedIn:https://linkedin.com/in/jdoe', $v);
        // ...plus a plain URL line that any importer understands...
        $this->assertStringContainsString('URL;TYPE=LinkedIn:https://linkedin.com/in/jdoe', $v);
        // ...and folded into NOTE as a final fallback.
        $this->assertStringContainsString('LinkedIn: https://linkedin.com/in/jdoe', $v);
        $this->assertStringContainsString('Instagram: https://instagram.com/jdoe', $v);
    }

    public function test_related_name_survives_even_if_the_extension_is_ignored(): void
    {
        $v = $this->vcf($this->contactCard(['related_name' => 'Jane Doe']));

        $this->assertStringContainsString('X-ABRELATEDNAMES', $v);
        $this->assertStringContainsString('Related: Jane Doe', $v);   // in NOTE too
    }

    public function test_fn_falls_back_to_company_when_there_is_no_person_name(): void
    {
        $card = app(CardGenerationService::class)->generate(1)->first();
        $card->contactProfile()->save($card->contactProfile()->make(['company' => 'Acme Ltd']));
        $card->forceFill(['nfc_mode' => 'contact'])->save();

        $v = $this->vcf($card->fresh());

        $this->assertStringContainsString('FN:Acme Ltd', $v);
        $this->assertStringContainsString('filename="Acme-Ltd.vcf"',
            (string) $this->get('/n/'.$card->public_id.'/contact.vcf')->headers->get('Content-Disposition'));
    }

    // ── XSS: a tap is a contact file, never a page ──────────────────────────

    public function test_malicious_html_in_contact_fields_can_never_render_as_a_page(): void
    {
        $card = $this->contactCard([
            'first_name' => '<script>alert(1)</script>',
            'company'    => '"><img src=x onerror=alert(2)>',
            'notes'      => '<iframe src="javascript:alert(3)"></iframe>',
            'job_title'  => "</title><script>alert(4)</script>",
        ]);

        // A tap returns the vCard itself, so the markup arrives verbatim - as
        // it must, it is someone's name. What stops it running is the type: a
        // browser only executes markup in a document it treats as HTML, and
        // nosniff forbids it from guessing otherwise.
        $r = $this->get('/n/'.$card->public_id)->assertOk();

        $this->assertSame('text/vcard; charset=utf-8', $r->headers->get('Content-Type'));
        $this->assertSame('nosniff', $r->headers->get('X-Content-Type-Options'));

        // And no value escaped its property line to become one of its own.
        foreach (array_filter(explode("\r\n", $r->getContent())) as $line) {
            $this->assertMatchesRegularExpression('/^[A-Z-]+[;:]/', $line);
        }
    }

    public function test_contact_page_exposes_no_card_internals(): void
    {
        $card = $this->contactCard(['company' => 'Acme']);

        $body = $this->get('/n/'.$card->public_id)->assertOk()->getContent();

        $this->assertStringNotContainsString($card->card_number, $body);
        $this->assertStringNotContainsString('/admin', $body);
        $this->assertStringNotContainsString('Activation', $body);
        $this->assertStringNotContainsString('"id":'.$card->id, $body);
        $this->assertStringNotContainsString($card->activation_pin_hash, $body);
    }

    public function test_unsafe_scheme_cannot_be_stored_in_a_contact_url_field(): void
    {
        $card = app(CardGenerationService::class)->generate(1)->first();
        $admin = \App\Models\User::create(['name'=>'A','email'=>'a@example.com','password'=>\Hash::make('correct-horse-1')]);

        foreach (['website', 'linkedin_url', 'other_social_url'] as $field) {
            $this->actingAs($admin)->from('/admin/cards/'.$card->public_id)
                ->patch('/admin/cards/'.$card->public_id.'/contact', [
                    'first_name' => 'John', $field => 'javascript:alert(1)',
                ])
                ->assertSessionHasErrors($field);
        }

        $this->assertNull($card->fresh()->contactProfile);
    }

    public function test_builder_output_is_stable_for_a_realistic_contact(): void
    {
        // Guards the exact wire format the phones parse.
        $card = $this->contactCard([
            'company' => 'Acme Ltd', 'job_title' => 'Director',
            'mobile_phone' => '+639171234567', 'work_email' => 'john@acme.test',
        ]);

        $v = app(VCardBuilder::class)->build($card->contactProfile);

        foreach ([
            'BEGIN:VCARD', 'VERSION:3.0', 'N:Doe;John;;;', 'FN:John Doe',
            'ORG:Acme Ltd', 'TITLE:Director', 'TEL;TYPE=CELL,VOICE:+639171234567',
            'EMAIL;TYPE=INTERNET,WORK:john@acme.test', 'REV:', 'END:VCARD',
        ] as $expected) {
            $this->assertStringContainsString($expected, $v);
        }
    }
}
