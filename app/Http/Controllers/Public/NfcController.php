<?php

namespace App\Http\Controllers\Public;

use App\Enums\NfcMode;
use App\Http\Controllers\Controller;
use App\Models\Card;
use App\Rules\SafeExternalUrl;
use App\Rules\SafePhoneNumber;
use App\Services\CardGenerationService;
use App\Services\VCardBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Public NFC endpoint.
 *
 * Unlike QR, NFC supports several links. The behaviour is derived purely from
 * how many active links exist - there is deliberately no "mode" column to get
 * out of sync with reality:
 *
 *   0 links -> setup page
 *   1 link  -> 302 straight to it
 *   2+      -> minimalist landing page
 */
class NfcController extends Controller
{
    public function __construct(private readonly CardGenerationService $cards) {}

    /**
     * GET /n/{public_id}
     *
     * The permanent URL never changes; only what it resolves to does. Dispatch
     * is on the card's current NFC mode.
     */
    public function show(Card $card): Response|RedirectResponse
    {
        return match ($card->nfcMode()) {
            NfcMode::Links   => $this->showLinks($card),
            NfcMode::Contact => $this->showContact($card),
            NfcMode::Phone   => $this->showPhone($card),
            NfcMode::Email   => $this->showEmail($card),
            NfcMode::Profile => $this->showProfile($card),
        };
    }

    /**
     * Links mode - unchanged from before NFC modes existed:
     *   0 links -> setup page, 1 -> 302, 2+ -> landing page.
     */
    private function showLinks(Card $card): Response|RedirectResponse
    {
        $links = $card->activeNfcLinks()->get();

        if ($links->isEmpty()) {
            return $this->noStore(response()->view('public.nfc-setup', ['card' => $card]));
        }

        if ($links->count() === 1) {
            return $this->noStore(redirect()->away($links->first()->url, 302));
        }

        return $this->noStore(response()->view('public.nfc-landing', [
            'card'  => $card,
            'links' => $links,
        ]));
    }

    private function showContact(Card $card): Response
    {
        $profile = $card->contactProfile;

        if (! $profile || ! $profile->isMeaningful()) {
            return $this->notReady($card);
        }

        return $this->noStore(response()->view('public.nfc-contact', [
            'card'    => $card,
            'profile' => $profile,
        ]));
    }

    private function showPhone(Card $card): Response
    {
        if ((string) $card->nfc_phone_number === '') {
            return $this->notReady($card);
        }

        return $this->noStore(response()->view('public.nfc-phone', [
            'card'     => $card,
            'display'  => $card->nfc_phone_number,
            'dialable' => SafePhoneNumber::toDialable($card->nfc_phone_number),
        ]));
    }

    private function showEmail(Card $card): Response
    {
        if ((string) $card->nfc_email_address === '') {
            return $this->notReady($card);
        }

        // Built here, not in Blade, so encoding is applied exactly once.
        $query = array_filter([
            'subject' => (string) $card->nfc_email_subject,
            'body'    => (string) $card->nfc_email_body,
        ], fn ($v) => $v !== '');

        $mailto = 'mailto:'.rawurlencode($card->nfc_email_address)
            .($query ? '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');

        return $this->noStore(response()->view('public.nfc-email', [
            'card'   => $card,
            'mailto' => $mailto,
        ]));
    }

    /**
     * Profile mode.
     *
     * Every card renders the not-ready page for now; the profile view lands in
     * the next task and adds the branch. Existing at all is what matters here:
     * an enum case with no match arm throws UnhandledMatchError, which would
     * take down a live public URL.
     */
    private function showProfile(Card $card): Response
    {
        return $this->notReady($card);
    }

    /**
     * Admin-managed modes with no data yet.
     *
     * Deliberately NOT the links setup form: contact/phone/email are configured
     * by the administrator, so a visitor must not be offered a self-service form
     * that would write links onto a card in a different mode.
     */
    private function notReady(Card $card): Response
    {
        return $this->noStore(response()->view('public.nfc-not-ready', ['card' => $card])->setStatusCode(200));
    }

    /**
     * GET /n/{public_id}/contact.vcf
     *
     * Generated fresh from the database on every request, which is exactly why
     * the physical tag never needs rewriting when details change.
     */
    public function vcard(Card $card, VCardBuilder $builder): Response
    {
        $profile = $card->contactProfile;

        abort_if($card->nfcMode() !== NfcMode::Contact || ! $profile || ! $profile->isMeaningful(), 404);

        // Filename is derived from an alphanumeric-only slug, so nothing from the
        // database can inject a quote, CR or LF into the Content-Disposition header.
        $filename = $profile->fileBaseName().'.vcf';

        return response($builder->build($profile), 200, [
            'Content-Type'        => 'text/vcard; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control'       => 'no-store, private, no-cache, must-revalidate, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag'        => 'noindex, nofollow',
        ]);
    }

    /** POST /n/{public_id} */
    public function store(Request $request, Card $card): RedirectResponse
    {
        // Public self-service setup exists for Links mode only.
        if ($card->nfcMode() !== NfcMode::Links || $card->activeNfcLinkCount() > 0) {
            return redirect()->route('nfc.show', $card->public_id);
        }

        if ($response = $this->tooManyAttempts($request, $card)) {
            return $response;
        }

        $data = $request->validate([
            'activation_pin' => ['required', 'string', 'digits:6'],
            'page_title'     => ['nullable', 'string', 'max:120'],
            'page_subtitle'  => ['nullable', 'string', 'max:200'],
            'links'                => ['required', 'array', 'min:1', 'max:12'],
            'links.*.title'        => ['required', 'string', 'max:120'],
            'links.*.url'          => ['required', 'string', new SafeExternalUrl()],
        ], [
            'links.required'       => 'Please add at least one link.',
            'links.*.title.required' => 'Every link needs a label.',
            'links.*.url.required'   => 'Every link needs a URL.',
        ], ['activation_pin' => 'activation PIN']);

        if (! $this->cards->verifyPin($card, $data['activation_pin'])) {
            RateLimiter::hit($this->throttleKey($request, $card), 900);

            return back()
                ->withErrors(['activation_pin' => 'That activation PIN is not correct.'])
                ->withInput($request->except('activation_pin'));
        }

        RateLimiter::clear($this->throttleKey($request, $card));

        DB::transaction(function () use ($card, $data) {
            $order = 0;

            foreach ($data['links'] as $link) {
                $card->nfcLinks()->create([
                    'title'      => trim($link['title']),
                    'url'        => trim($link['url']),
                    'sort_order' => $order++,
                    'is_active'  => true,
                ]);
            }

            $card->forceFill([
                // Nullable fields are absent from $data entirely when not submitted,
                // so coalesce before using them.
                'nfc_page_title'    => trim((string) ($data['page_title'] ?? '')) ?: null,
                'nfc_page_subtitle' => trim((string) ($data['page_subtitle'] ?? '')) ?: null,
                'nfc_configured_at' => now(),
            ])->save();
        });

        return redirect()->route('nfc.done', $card->public_id);
    }

    /** GET /n/{public_id}/done */
    public function done(Card $card): Response
    {
        return $this->noStore(response()->view('public.nfc-done', ['card' => $card]));
    }

    private function throttleKey(Request $request, Card $card): string
    {
        return 'nfc|'.$card->public_id.'|'.$request->ip();
    }

    private function tooManyAttempts(Request $request, Card $card): ?RedirectResponse
    {
        $key = $this->throttleKey($request, $card);

        if (RateLimiter::tooManyAttempts($key, 5)) {
            return back()->withErrors([
                'activation_pin' => 'Too many incorrect attempts. Please try again in '
                    .ceil(RateLimiter::availableIn($key) / 60).' minute(s).',
            ]);
        }

        return null;
    }

    private function noStore(Response|RedirectResponse $response): Response|RedirectResponse
    {
        return $response
            ->header('Cache-Control', 'no-store, private, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
