<?php

namespace App\Http\Controllers\Admin;

use App\Enums\NfcMode;
use App\Exceptions\UnreadableImageException;
use App\Http\Controllers\Controller;
use App\Models\Card;
use App\Models\StickerTemplate;
use App\Models\NfcLink;
use App\Rules\SafeExternalUrl;
use App\Rules\SafePhoneNumber;
use App\Services\CardGenerationService;
use App\Services\CardReviewStickerService;
use App\Services\CardResetService;
use App\Services\LinkPlatform;
use App\Services\PublicImageStorage;
use App\Services\ImageIngest;
use App\Services\QrCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CardController extends Controller
{
    public function __construct(
        private readonly CardGenerationService $cards,
        private readonly QrCodeService $qr,
        private readonly CardResetService $reset,
        private readonly PublicImageStorage $photos,
        private readonly ImageIngest $images,
        private readonly CardReviewStickerService $stickers,
    ) {}

    public function show(Card $card): Response
    {
        $card->load('nfcLinks');

        return response()->view('admin.card', [
            'card' => $card,
            'qrSvg' => $this->qr->svgForCard($card),
            'latestProfileEdit' => $card->profileEdits()->first(),
            'activeStickerTemplate' => StickerTemplate::active(),
        ])->header('Cache-Control', 'no-store, private, no-cache, must-revalidate, max-age=0');
    }

    // --- QR ------------------------------------------------------------------

    /** PATCH /admin/cards/{card}/qr */
    public function setQrDestination(Request $request, Card $card): RedirectResponse
    {
        $data = $request->validate([
            'destination' => ['required', 'string', new SafeExternalUrl()],
        ]);

        $card->forceFill([
            'qr_destination'   => trim($data['destination']),
            'qr_configured_at' => $card->qr_configured_at ?? now(),
        ])->save();

        return back()->with('status', 'QR destination updated.');
    }

    /**
     * DELETE /admin/cards/{card}/qr
     * Clears ONLY the QR destination. NFC links, public_id and both permanent
     * URLs are untouched, so the printed QR keeps working - it simply shows the
     * setup page again.
     */
    public function resetQr(Card $card): RedirectResponse
    {
        $card->forceFill(['qr_destination' => null, 'qr_configured_at' => null])->save();

        return back()->with('status', 'QR reset. The next scan will show the setup page.');
    }

    // --- NFC -----------------------------------------------------------------

    /** POST /admin/cards/{card}/nfc-links */
    public function addNfcLink(Request $request, Card $card): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'url'   => ['required', 'string', new SafeExternalUrl()],
            'icon'  => ['nullable', 'string', Rule::in(array_keys(LinkPlatform::iconChoices()))],
        ]);

        $card->nfcLinks()->create([
            'title'      => trim($data['title']),
            'url'        => trim($data['url']),
            'icon'       => $data['icon'] ?? null,
            'sort_order' => (int) ($card->nfcLinks()->max('sort_order') ?? -1) + 1,
            'is_active'  => true,
        ]);

        if ($card->nfc_configured_at === null) {
            $card->forceFill(['nfc_configured_at' => now()])->save();
        }

        return back()->with('status', 'Link added.');
    }

    /** PATCH /admin/cards/{card}/nfc-links/{link} */
    public function updateNfcLink(Request $request, Card $card, NfcLink $link): RedirectResponse
    {
        abort_unless($link->card_id === $card->id, 404);

        $data = $request->validate([
            'title'     => ['required', 'string', 'max:120'],
            'url'       => ['required', 'string', new SafeExternalUrl()],
            'icon'      => ['nullable', 'string', Rule::in(array_keys(LinkPlatform::iconChoices()))],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $link->update([
            'title'     => trim($data['title']),
            'url'       => trim($data['url']),
            'icon'      => $data['icon'] ?? null,
            'is_active' => $request->boolean('is_active'),
        ]);

        return back()->with('status', 'Link updated.');
    }

    /** DELETE /admin/cards/{card}/nfc-links/{link} */
    public function deleteNfcLink(Card $card, NfcLink $link): RedirectResponse
    {
        abort_unless($link->card_id === $card->id, 404);
        $link->delete();

        return back()->with('status', 'Link deleted.');
    }

    /** PATCH /admin/cards/{card}/nfc-links/{link}/move */
    public function moveNfcLink(Request $request, Card $card, NfcLink $link): RedirectResponse
    {
        abort_unless($link->card_id === $card->id, 404);
        $request->validate(['direction' => ['required', 'in:up,down']]);

        $ordered = $card->nfcLinks()->get()->values();
        $index   = $ordered->search(fn ($l) => $l->id === $link->id);
        $swap    = $request->input('direction') === 'up' ? $index - 1 : $index + 1;

        if ($index !== false && isset($ordered[$swap])) {
            DB::transaction(function () use ($ordered, $index, $swap) {
                $a = $ordered[$index];
                $b = $ordered[$swap];
                [$a->sort_order, $b->sort_order] = [$b->sort_order, $a->sort_order];
                $a->save();
                $b->save();
            });
        }

        return back()->with('status', 'Link order updated.');
    }

    /**
     * DELETE /admin/cards/{card}/nfc
     *
     * Clears the whole NFC side in every mode and returns the card to Links
     * mode, so the confirmation message below is true regardless of what the
     * card was serving. QR is untouched. The surgical "remove just this" case
     * is served by the per-mode delete actions.
     */
    public function resetNfc(Card $card): RedirectResponse
    {
        $this->reset->resetNfcSide($card);

        return back()->with('status', 'NFC reset. Every mode has been cleared and the next tap will show the setup page.');
    }

    // --- NFC mode ------------------------------------------------------------

    /**
     * PATCH /admin/cards/{card}/nfc-mode
     *
     * Switching mode changes only which data the permanent URL serves. No other
     * mode's data is touched, so switching away and back is lossless. Deletion
     * is always an explicit, separate action.
     */
    public function setNfcMode(Request $request, Card $card): RedirectResponse
    {
        $data = $request->validate([
            'nfc_mode' => ['required', Rule::enum(NfcMode::class)],
        ]);

        $card->forceFill(['nfc_mode' => $data['nfc_mode']])->save();

        return back()->with('status', 'NFC mode set to '.NfcMode::from($data['nfc_mode'])->label()
            .'. The permanent NFC URL is unchanged - the physical tag does not need reprogramming.');
    }

    // --- Contact card --------------------------------------------------------

    /** PATCH /admin/cards/{card}/contact */
    public function updateContactProfile(Request $request, Card $card): RedirectResponse
    {
        $url   = ['nullable', 'string', 'max:500', new SafeExternalUrl()];
        $phone = ['nullable', 'string', new SafePhoneNumber()];

        $data = $request->validate([
            'first_name'  => ['nullable', 'string', 'max:80'],
            'middle_name' => ['nullable', 'string', 'max:80'],
            'last_name'   => ['nullable', 'string', 'max:80'],
            'suffix'      => ['nullable', 'string', 'max:30'],
            'job_title'   => ['nullable', 'string', 'max:120'],
            'company'     => ['nullable', 'string', 'max:150'],

            'mobile_phone' => $phone, 'work_phone' => $phone,
            'landline'     => $phone, 'other_phone' => $phone,

            'personal_email' => ['nullable', 'email:rfc', 'max:190'],
            'work_email'     => ['nullable', 'email:rfc', 'max:190'],

            'website' => $url,

            'street'      => ['nullable', 'string', 'max:200'],
            'city'        => ['nullable', 'string', 'max:100'],
            'state'       => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:30'],
            'country'     => ['nullable', 'string', 'max:100'],

            'birthday'     => ['nullable', 'date', 'before:tomorrow'],
            'related_name' => ['nullable', 'string', 'max:120'],
            'notes'        => ['nullable', 'string', 'max:2000'],

            'facebook_url'  => $url, 'instagram_url' => $url, 'linkedin_url' => $url,
            'tiktok_url'    => $url, 'x_url'         => $url, 'youtube_url'  => $url,
            'other_social_url' => $url,

            'photo' => ['nullable', 'file', 'image', 'mimes:jpeg,png,webp', 'max:2048', 'dimensions:max_width=4000,max_height=4000'],
        ]);

        $profile = $card->contactProfile ?: $card->contactProfile()->make();

        // Every value is stripped of CR/LF: these fields land in a vCard, where a
        // newline would terminate the property line and inject a new one.
        $clean = [];
        foreach (Arr::except($data, ['photo']) as $k => $v) {
            $clean[$k] = is_string($v) ? $this->stripControl($v) : $v;
        }

        $profile->fill($clean);

        if ($file = $request->file('photo')) {
            $old = $profile->photo_path;

            try {
                $profile->photo_path = $this->images->store($file, 'contact-photos', 800);
            } catch (UnreadableImageException $e) {
                // A bad file reads as a normal validation error on the field it
                // came from, rather than as a raw 422 page.
                return back()->withErrors(['photo' => $e->getMessage()])->withInput($request->except('photo'));
            }

            $this->photos->forget($old, $card);
        }

        if (! $profile->exists) {
            $card->contactProfile()->save($profile);
        } else {
            $profile->save();
        }

        if (! $profile->isMeaningful()) {
            return back()->with('status', 'Saved. Add a name or company before this card can display a contact.');
        }

        return back()->with('status', 'Contact details saved.');
    }

    /** DELETE /admin/cards/{card}/contact/photo */
    public function deleteContactPhoto(Card $card): RedirectResponse
    {
        $profile = $card->contactProfile;

        if ($profile && $profile->photo_path) {
            $path = $profile->photo_path;
            $profile->forceFill(['photo_path' => null])->save();
            $this->photos->forget($path, $card);
        }

        return back()->with('status', 'Contact photo removed.');
    }

    /**
     * DELETE /admin/cards/{card}/contact
     * Explicit only - mode switching never deletes this.
     */
    public function deleteContactProfile(Card $card): RedirectResponse
    {
        $profile = $card->contactProfile;

        if ($profile) {
            $path = $profile->photo_path;
            $profile->delete();
            $this->photos->forget($path, $card);
        }

        return back()->with('status', 'Contact details deleted.');
    }

    // --- Phone mode ----------------------------------------------------------

    /** PATCH /admin/cards/{card}/phone */
    public function updatePhone(Request $request, Card $card): RedirectResponse
    {
        $data = $request->validate([
            'nfc_phone_number' => ['required', 'string', new SafePhoneNumber()],
        ]);

        $card->forceFill(['nfc_phone_number' => trim($data['nfc_phone_number'])])->save();

        return back()->with('status', 'Phone number saved.');
    }

    /** DELETE /admin/cards/{card}/phone */
    public function deletePhone(Card $card): RedirectResponse
    {
        $card->forceFill(['nfc_phone_number' => null])->save();

        return back()->with('status', 'Phone number cleared.');
    }

    // --- Email mode ----------------------------------------------------------

    /** PATCH /admin/cards/{card}/email */
    public function updateEmail(Request $request, Card $card): RedirectResponse
    {
        $data = $request->validate([
            'nfc_email_address' => ['required', 'email:rfc', 'max:190'],
            'nfc_email_subject' => ['nullable', 'string', 'max:200'],
            'nfc_email_body'    => ['nullable', 'string', 'max:2000'],
        ]);

        $card->forceFill([
            'nfc_email_address' => $this->stripControl($data['nfc_email_address']),
            'nfc_email_subject' => $this->stripControlOrNull($data['nfc_email_subject'] ?? null),
            'nfc_email_body'    => (string) ($data['nfc_email_body'] ?? '') !== ''
                ? str_replace(["\r\n", "\r"], "\n", $data['nfc_email_body'])
                : null,
        ])->save();

        return back()->with('status', 'Email action saved.');
    }

    /** DELETE /admin/cards/{card}/email */
    public function deleteEmail(Card $card): RedirectResponse
    {
        $card->forceFill([
            'nfc_email_address' => null, 'nfc_email_subject' => null, 'nfc_email_body' => null,
        ])->save();

        return back()->with('status', 'Email action cleared.');
    }

    // --- helpers -------------------------------------------------------------

    /**
     * Removes CR, LF and other control characters that could inject a vCard line
     * or a header.
     *
     * The encoding scrub comes first because the pattern is /u, and a /u pattern
     * returns null on malformed UTF-8 - which the `?? ''` below then turned into
     * the whole value being thrown away over one bad byte. Refusing the bad bytes
     * is load-bearing (these strings reach utf8mb4 columns, and MySQL rejects
     * invalid sequences outright), but losing the valid characters with them is a
     * bigger loss than the problem. mb_convert_encoding replaces each invalid
     * sequence with "?", so the admin sees a name with a blemish they can fix
     * rather than a field that silently emptied itself. It is a no-op on the
     * normal path, where the input is already valid UTF-8.
     *
     * iconv('UTF-8', 'UTF-8//IGNORE') would drop the bytes rather than mark
     * them, but truncates the rest of the string at the first bad byte on some
     * libiconv builds. Predictability wins.
     */
    private function stripControl(string $v): string
    {
        $valid = mb_convert_encoding($v, 'UTF-8', 'UTF-8');

        return trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $valid) ?? '');
    }

    /**
     * stripControl(), with a value that normalises to nothing expressed as NULL.
     *
     * Not `?: null`, which is how a display name of "0" and an email subject of
     * "0" both came to be stored as NULL: PHP reads "0" as falsy, so the save
     * reported success while quietly discarding what the admin typed. Short
     * numeric values are exactly what these fields get - a year, a ticket
     * number, a table number.
     */
    private function stripControlOrNull(?string $v): ?string
    {
        $clean = $this->stripControl((string) $v);

        return $clean === '' ? null : $clean;
    }

    // --- Whole card ----------------------------------------------------------

    /**
     * DELETE /admin/cards/{card}/reset
     * Clears all configuration but KEEPS card_number, public_id and therefore
     * both permanent URLs - the physical card stays valid. The activation PIN is
     * deliberately preserved unless the admin regenerates it explicitly.
     */
    public function resetCard(Card $card): RedirectResponse
    {
        $this->reset->resetEverything($card);

        return back()->with('status', 'Card reset. Both sides are blank. Card number, public ID, both permanent URLs and the activation PIN are unchanged.');
    }

    // --- Activation PIN ------------------------------------------------------

    /**
     * POST /admin/cards/{card}/pin/reveal
     *
     * Always returns the universal PIN. Kept as an authenticated POST returning
     * no-store JSON so the value never sits in page markup or a GET-able URL.
     */
    public function revealPin(Card $card): JsonResponse
    {
        return response()
            ->json(['pin' => $this->cards->revealPin($card)])
            ->header('Cache-Control', 'no-store, private, no-cache, must-revalidate, max-age=0');
    }

    // --- QR downloads --------------------------------------------------------

    public function downloadSvg(Card $card): Response
    {
        return response($this->qr->svgForCard($card), 200, [
            'Content-Type'        => 'image/svg+xml',
            'Content-Disposition' => 'attachment; filename="'.$card->assetBasename().'.svg"',
            'Cache-Control'       => 'no-store, private',
        ]);
    }

    public function downloadPng(Card $card): Response
    {
        return response($this->qr->pngForCard($card), 200, [
            'Content-Type'        => 'image/png',
            'Content-Disposition' => 'attachment; filename="'.$card->assetBasename().'.png"',
            'Cache-Control'       => 'no-store, private',
        ]);
    }

    public function downloadStickerTemplate(Card $card): Response
    {
        $basename = $this->stickers->downloadBasename($card);

        return response($this->stickers->zipFor($card), 200, [
            'Content-Type'        => 'application/zip',
            'Content-Disposition' => 'attachment; filename="'.$basename.'-template.zip"',
            'Cache-Control'       => 'no-store, private',
        ]);
    }
}
