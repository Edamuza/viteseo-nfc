<?php

namespace App\Http\Controllers\Public;

use App\Enums\NfcMode;
use App\Exceptions\UnreadableImageException;
use App\Http\Controllers\Controller;
use App\Models\Card;
use App\Models\SitePage;
use App\Rules\SafeExternalUrl;
use App\Rules\SafePhoneNumber;
use App\Services\CardGenerationService;
use App\Services\CardOwnerGate;
use App\Services\ContactCard;
use App\Services\ImageIngest;
use App\Services\LinkPlatform;
use App\Services\PublicImageStorage;
use App\Services\QrCodeService;
use App\Services\VCardBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Throwable;

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
    public function __construct(
        private readonly CardGenerationService $cards,
        private readonly QrCodeService $qr,
        private readonly CardOwnerGate $gate,
        private readonly ImageIngest $images,
        private readonly PublicImageStorage $photos,
        private readonly VCardBuilder $vcards,
    ) {}

    /**
     * May this request use a setup form? Always for an unconfigured card;
     * for a live one only after the owner unlocked the manage page — that
     * unlock is what lets an owner switch a working card to another mode.
     */
    private function setupBlocked(Request $request, Card $card): bool
    {
        return $card->nfcModeIsConfigured() && ! $this->gate->isUnlocked($request, $card);
    }

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
            return $this->chooseSetup($card);
        }

        if ($links->count() === 1) {
            return $this->noStore(redirect()->away($links->first()->url, 302));
        }

        return $this->noStore(response()->view('public.nfc-landing', [
            'card'  => $card,
            'links' => $links,
        ]));
    }

    /**
     * Contact mode hands the tap straight to the phone's own contact screen.
     *
     * No page of ours in between. An iPhone opens a vCard served inline as
     * "Create New Contact"; Android saves the file and offers to open it in
     * Contacts. The visitor saves or edits on a screen their phone already
     * knows how to use, and everything the card holds arrives with it.
     */
    private function showContact(Card $card): Response
    {
        $contact = ContactCard::for($card);

        if (! $contact) {
            return $this->notReady($card);
        }

        return $this->vcardResponse($contact, 'inline');
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
     * A card with no profile, or a profile with no display name, is not ready.
     * Existing at all is what matters for dispatch: an enum case with no match
     * arm throws UnhandledMatchError, which would take down a live public URL.
     */
    private function showProfile(Card $card): Response
    {
        $profile = $card->profile;

        if (! $profile || ! $profile->isMeaningful()) {
            return $this->notReady($card);
        }

        $shareMailto = 'mailto:?'.http_build_query([
            'subject' => 'View '.$profile->display_name."'s profile",
            'body'    => 'Open the profile: '.$card->nfcUrl(),
        ], '', '&', PHP_QUERY_RFC3986);

        return $this->noStore(response()->view('public.nfc-profile', [
            'card'        => $card,
            'profile'     => $profile,
            'cardQrSvg'   => $this->qr->svgForCard($card),
            'shareMailto' => $shareMailto,
            'sitePages'   => SitePage::query()
                ->whereIn('key', ['about', 'terms', 'privacy'])
                ->get()
                ->keyBy('key'),
            // The same rows Links mode serves as plain buttons, so switching
            // between the two modes loses nothing.
            'links'   => $card->activeNfcLinks()->get(),
        ]));
    }

    public function profileQr(Card $card): Response
    {
        $profile = $card->profile;

        abort_if($card->nfcMode() !== NfcMode::Profile || ! $profile || ! $profile->isMeaningful(), 404);

        return response($this->qr->pngForProfile($card, $profile), 200, [
            'Content-Type'           => 'image/png',
            'Content-Disposition'    => 'attachment; filename="profile-'.$card->public_id.'-qr.png"',
            'Cache-Control'          => 'no-store, private, no-cache, must-revalidate, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag'           => 'noindex, nofollow',
        ]);
    }

    /**
     * Admin-managed modes with no data yet.
     *
     * Deliberately NOT the links setup form: every mode except Links is
     * configured by the administrator, so a visitor must not be offered a
     * self-service form that would write links onto a card in a different mode.
     */
    /**
     * An unconfigured card is the customer's starting point, not a dead end:
     * every visible mode routes to the setup chooser. Email is hidden from
     * self-setup, so an empty Email-mode card keeps the plain not-ready page.
     */
    private function notReady(Card $card): Response
    {
        if ($card->nfcMode() === NfcMode::Email) {
            return $this->noStore(response()->view('public.nfc-not-ready', ['card' => $card])->setStatusCode(200));
        }

        return $this->chooseSetup($card);
    }

    private function chooseSetup(Card $card): Response
    {
        return $this->noStore(response()->view('public.nfc-choose', ['card' => $card]));
    }

    /**
     * GET /n/{public_id}/setup/{links|phone|contact}
     *
     * Profile setup is not here: the chooser sends that choice to the existing
     * PIN-gated profile editor, which handles the empty card already.
     */
    public function setupForm(Request $request, Card $card, string $mode): Response|RedirectResponse
    {
        if ($this->setupBlocked($request, $card)) {
            return redirect()->route('nfc.show', $card->public_id);
        }

        // Step 2 of 3. One question per screen: owners were abandoning setup
        // on a single page that asked for a PIN, a new PIN and every detail
        // at once. The PIN is settled here, then the details screen is about
        // the card alone.
        if (! $this->gate->isUnlocked($request, $card)) {
            return $this->noStore(response()->view('public.nfc-setup-unlock', [
                'card' => $card,
                'mode' => $this->modeFor($mode),
            ]));
        }

        // Step 3, prefilled with whatever the card already holds for that mode,
        // so switching modes from the manage page starts from the saved data.
        return $this->noStore(response()->view(match ($mode) {
            'phone'   => 'public.nfc-setup-phone',
            'contact' => 'public.nfc-setup-contact',
            default   => 'public.nfc-setup',
        }, [
            'card' => $card,
            'contactProfile' => $mode === 'contact' ? $card->contactProfile : null,
            // What any blank on the contact form will be filled with.
            'fromProfile' => $mode === 'contact' ? ContactCard::fromProfile($card->profile) : [],
            'existingLinks' => $mode === 'links'
                ? $card->nfcLinks()->orderBy('sort_order')->get()
                : collect(),
        ]));
    }

    /**
     * POST /n/{public_id}/setup/{links|phone|contact}/unlock
     *
     * Step 2's only job: prove ownership once, and retire the universal PIN
     * while doing it. Success opens the same session unlock the manage page
     * and the profile editor use, so the details screen that follows asks for
     * no PIN at all.
     */
    public function unlockSetup(Request $request, Card $card, string $mode): RedirectResponse
    {
        if ($this->setupBlocked($request, $card)) {
            return redirect()->route('nfc.show', $card->public_id);
        }

        if ($response = $this->tooManyAttempts($request, $card)) {
            return $response;
        }

        $data = $request->validate(
            ['activation_pin' => ['required', 'string', 'digits:6']] + $this->newPinRulesFor($card),
            $this->cards->newPinMessages(),
            ['activation_pin' => 'activation PIN', 'new_pin' => 'new PIN']
        );

        if ($response = $this->rejectWrongPin($request, $card, $data['activation_pin'])) {
            return $response;
        }

        $this->personalizeIfProvided($card, $data);
        $this->gate->unlock($request, $card);

        return redirect()->route('nfc.setup', [$card->public_id, $mode]);
    }

    private function modeFor(string $mode): NfcMode
    {
        return match ($mode) {
            'phone'   => NfcMode::Phone,
            'contact' => NfcMode::Contact,
            default   => NfcMode::Links,
        };
    }

    /** POST /n/{public_id}/setup/phone */
    public function storePhone(Request $request, Card $card): RedirectResponse
    {
        if ($this->setupBlocked($request, $card)) {
            return redirect()->route('nfc.show', $card->public_id);
        }

        if ($response = $this->tooManyAttempts($request, $card)) {
            return $response;
        }

        $data = $request->validate([
            'nfc_phone_number' => ['required', 'string', new SafePhoneNumber()],
        ] + $this->pinRuleFor($request, $card) + $this->newPinRulesFor($card), $this->pinMessages() + $this->cards->newPinMessages(), [
            'activation_pin' => 'activation PIN',
            'nfc_phone_number' => 'phone number',
            'new_pin' => 'new PIN',
        ]);

        if ($response = $this->rejectUnverified($request, $card, $data)) {
            return $response;
        }

        $card->forceFill([
            'nfc_mode'          => NfcMode::Phone,
            'nfc_phone_number'  => trim($data['nfc_phone_number']),
            'nfc_configured_at' => now(),
        ])->save();

        $this->personalizeIfProvided($card, $data);

        return redirect()->route('nfc.done', $card->public_id);
    }

    /** POST /n/{public_id}/setup/contact */
    public function storeContact(Request $request, Card $card): RedirectResponse
    {
        if ($this->setupBlocked($request, $card)) {
            return redirect()->route('nfc.show', $card->public_id);
        }

        if ($response = $this->tooManyAttempts($request, $card)) {
            return $response;
        }

        // A named profile page already gives the contact card its name (see
        // ContactCard), so only a card without one must be told to add it.
        $nameRequired = ! $card->profile?->isMeaningful();

        $data = $request->validate([
            'first_name'     => ['nullable', ...($nameRequired ? ['required_without:company'] : []), 'string', 'max:80'],
            'last_name'      => ['nullable', 'string', 'max:80'],
            'job_title'      => ['nullable', 'string', 'max:120'],
            'company'        => ['nullable', ...($nameRequired ? ['required_without:first_name'] : []), 'string', 'max:150'],
            'mobile_phone'   => ['nullable', 'string', new SafePhoneNumber()],
            'personal_email' => ['nullable', 'email:rfc', 'max:190'],
            'website'        => ['nullable', 'string', 'max:500', new SafeExternalUrl()],
            'photo'          => ['nullable', 'file', 'image', 'mimes:jpeg,png,webp', 'max:5120', 'dimensions:max_width=8000,max_height=8000'],
            'remove_photo'   => ['nullable', 'boolean'],
        ] + $this->pinRuleFor($request, $card) + $this->newPinRulesFor($card), [
            'first_name.required_without' => 'Add at least a name or a company.',
            'company.required_without'    => 'Add at least a name or a company.',
        ] + $this->pinMessages() + $this->cards->newPinMessages(), ['activation_pin' => 'activation PIN', 'new_pin' => 'new PIN']);

        if ($response = $this->rejectUnverified($request, $card, $data)) {
            return $response;
        }

        // Stored only once ownership is proven, so a stranger's upload never
        // reaches the disk.
        $newPath = null;
        if ($file = $request->file('photo')) {
            try {
                $newPath = $this->images->store($file, 'contact-photos', 800);
            } catch (UnreadableImageException $e) {
                return back()->withErrors(['photo' => $e->getMessage()])->withInput($request->except('photo'));
            }
        }

        $oldPath = $card->contactProfile?->photo_path;

        try {
            DB::transaction(function () use ($card, $data, $newPath): void {
                $profile = $card->contactProfile ?: $card->contactProfile()->make();

                // Same rule as the admin form: these values land in a vCard, where
                // a stray CR/LF would terminate the property line.
                $clean = [];
                foreach (['first_name', 'last_name', 'job_title', 'company', 'mobile_phone', 'personal_email', 'website'] as $field) {
                    $value = $data[$field] ?? null;
                    $clean[$field] = is_string($value)
                        ? (trim((string) preg_replace('/[\r\n]+/', ' ', $value)) ?: null)
                        : $value;
                }

                $profile->fill($clean);

                if ($newPath !== null) {
                    $profile->photo_path = $newPath;
                } elseif ((bool) ($data['remove_photo'] ?? false)) {
                    $profile->photo_path = null;
                }

                $profile->save();

                $card->forceFill([
                    'nfc_mode'          => NfcMode::Contact,
                    'nfc_configured_at' => now(),
                ])->save();
            });
        } catch (Throwable $e) {
            if ($newPath !== null) {
                $this->photos->forget($newPath, $card);
            }

            throw $e;
        }

        // After the commit: a delete cannot be rolled back, and a file removed
        // while its row survived would break the photo on a live card.
        $card->unsetRelation('contactProfile');
        if ((string) $oldPath !== '' && $oldPath !== $card->contactProfile?->photo_path) {
            $this->photos->forget($oldPath, $card);
        }

        $this->personalizeIfProvided($card, $data);

        return redirect()->route('nfc.done', $card->public_id);
    }

    /** @return array<string, array<int, mixed>> */
    private function newPinRulesFor(Card $card): array
    {
        return $card->pinIsPersonalized() ? [] : $this->cards->newPinRules();
    }

    /**
     * The activation-PIN rule for a save.
     *
     * An owner who cleared step 2 proved ownership for this session, so the
     * details screen carries no PIN field at all. The rule stays `required`
     * for anyone posting to the endpoint without that unlock - which is also
     * the one-page path every earlier client used.
     *
     * @return array<string, array<int, string>>
     */
    private function pinRuleFor(Request $request, Card $card): array
    {
        return ['activation_pin' => [
            $this->gate->isUnlocked($request, $card) ? 'nullable' : 'required',
            'string',
            'digits:6',
        ]];
    }

    /**
     * What a save says when it carries no proof of ownership at all.
     *
     * The details screen has no PIN field - step 2 settled that - so an unlock
     * that expired mid-form would otherwise fail with "the activation PIN
     * field is required" about a field the owner cannot see. The screen they
     * land back on IS the unlock screen, so the wording points at it.
     *
     * @return array<string, string>
     */
    private function pinMessages(): array
    {
        return [
            'activation_pin.required' => 'Your card locked itself again after a while. Please enter your PIN once more.',
        ];
    }

    /**
     * Refuse the save unless this request proved ownership - by session unlock
     * or by a correct PIN in the payload.
     *
     * @param  array<string, mixed>  $data
     */
    private function rejectUnverified(Request $request, Card $card, array $data): ?RedirectResponse
    {
        $pin = (string) ($data['activation_pin'] ?? '');

        // Empty is only reachable when pinRuleFor() made the field optional,
        // which happens only for an already-unlocked session.
        return $pin === '' ? null : $this->rejectWrongPin($request, $card, $pin);
    }

    /** @param  array<string, mixed>  $data */
    private function personalizeIfProvided(Card $card, array $data): void
    {
        if (isset($data['new_pin'])) {
            $this->cards->personalizePin($card, $data['new_pin']);
        }
    }

    private function rejectWrongPin(Request $request, Card $card, string $pin): ?RedirectResponse
    {
        if ($this->cards->verifyPin($card, $pin)) {
            RateLimiter::clear($this->throttleKey($request, $card));

            return null;
        }

        RateLimiter::hit($this->throttleKey($request, $card), 900);

        return back()
            ->withErrors(['activation_pin' => 'That activation PIN is not correct.'])
            ->withInput($request->except('activation_pin'));
    }

    /**
     * GET /n/{public_id}/contact.vcf
     *
     * Generated fresh from the database on every request, which is exactly why
     * the physical tag never needs rewriting when details change.
     */
    public function vcard(Card $card): Response
    {
        $contact = $card->nfcMode() === NfcMode::Contact ? ContactCard::for($card) : null;

        abort_if(! $contact, 404);

        return $this->vcardResponse($contact, 'attachment');
    }

    /**
     * The contact file, served either inline (the tap itself) or as a download.
     *
     * Inline is what makes an iPhone open its contact sheet instead of asking
     * whether to download a file; the filename still names the download on
     * Android, which saves the file either way.
     */
    private function vcardResponse(ContactCard $contact, string $disposition): Response
    {
        // Filename is derived from an alphanumeric-only slug, so nothing from the
        // database can inject a quote, CR or LF into the Content-Disposition header.
        $filename = $contact->details->fileBaseName().'.vcf';

        return response($this->vcards->build($contact->details, $contact->urls), 200, [
            'Content-Type'        => 'text/vcard; charset=utf-8',
            'Content-Disposition' => $disposition.'; filename="'.$filename.'"',
            'Cache-Control'       => 'no-store, private, no-cache, must-revalidate, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag'        => 'noindex, nofollow',
        ]);
    }

    public function profileVcard(Card $card, VCardBuilder $builder): Response
    {
        $profile = $card->profile;

        abort_if($card->nfcMode() !== NfcMode::Profile || ! $profile || ! $profile->isMeaningful(), 404);

        return response($builder->buildProfile($profile, $card->activeNfcLinks()->get()), 200, [
            'Content-Type'           => 'text/vcard; charset=utf-8',
            'Content-Disposition'    => 'attachment; filename="profile-'.$card->public_id.'.vcf"',
            'Cache-Control'          => 'no-store, private, no-cache, must-revalidate, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag'           => 'noindex, nofollow',
        ]);
    }

    /** POST /n/{public_id} */
    public function store(Request $request, Card $card): RedirectResponse
    {
        // Self-service setup, or a mode switch by an owner who unlocked the
        // manage page. A live card with no owner unlock is never changed
        // from here; the PIN below guards everything else.
        if ($this->setupBlocked($request, $card)) {
            return redirect()->route('nfc.show', $card->public_id);
        }

        if ($response = $this->tooManyAttempts($request, $card)) {
            return $response;
        }

        $data = $request->validate([
            'page_title'     => ['nullable', 'string', 'max:120'],
            'page_subtitle'  => ['nullable', 'string', 'max:200'],
            'links'                => ['required', 'array', 'min:1', 'max:12'],
            'links.*.title'        => ['required', 'string', 'max:120'],
            'links.*.url'          => ['required', 'string', new SafeExternalUrl()],
            'links.*.icon'         => ['nullable', 'string', Rule::in(array_keys(LinkPlatform::iconChoices()))],
        ] + $this->pinRuleFor($request, $card) + $this->newPinRulesFor($card), [
            'links.required'       => 'Please add at least one link.',
            'links.*.title.required' => 'Every link needs a label.',
            'links.*.url.required'   => 'Every link needs a URL.',
        ] + $this->pinMessages() + $this->cards->newPinMessages(), ['activation_pin' => 'activation PIN', 'new_pin' => 'new PIN']);

        if ($response = $this->rejectUnverified($request, $card, $data)) {
            return $response;
        }

        DB::transaction(function () use ($card, $data) {
            // The form is the full picture of what the card's links should
            // be — prefilled with the existing rows when switching modes —
            // so saving replaces rather than appends. Appending would
            // duplicate every prefilled row on a profile-to-links switch.
            $card->nfcLinks()->delete();

            $order = 0;

            foreach ($data['links'] as $link) {
                $card->nfcLinks()->create([
                    'title'      => trim($link['title']),
                    'url'        => trim($link['url']),
                    'icon'       => $link['icon'] ?? null,
                    'sort_order' => $order++,
                    'is_active'  => true,
                ]);
            }

            $card->forceFill([
                // The chooser lets an unconfigured card in any visible mode
                // become a Links card, so the mode is set here explicitly.
                'nfc_mode'          => NfcMode::Links,
                // Nullable fields are absent from $data entirely when not submitted,
                // so coalesce before using them.
                'nfc_page_title'    => trim((string) ($data['page_title'] ?? '')) ?: null,
                'nfc_page_subtitle' => trim((string) ($data['page_subtitle'] ?? '')) ?: null,
                'nfc_configured_at' => now(),
            ])->save();
        });

        $this->personalizeIfProvided($card, $data);

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
