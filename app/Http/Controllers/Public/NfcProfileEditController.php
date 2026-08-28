<?php

namespace App\Http\Controllers\Public;

use App\Enums\NfcMode;
use App\Exceptions\UnreadableImageException;
use App\Http\Controllers\Controller;
use App\Models\Card;
use App\Rules\SafeExternalUrl;
use App\Rules\SafePhoneNumber;
use App\Services\CardGenerationService;
use App\Services\CardOwnerGate;
use App\Services\LinkPlatform;
use App\Services\DominantColor;
use App\Services\ImageIngest;
use App\Services\PublicImageStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

class NfcProfileEditController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    private const DECAY_SECONDS = 900;

    public function __construct(
        private readonly CardGenerationService $cards,
        private readonly ImageIngest $images,
        private readonly DominantColor $colors,
        private readonly PublicImageStorage $photos,
        private readonly CardOwnerGate $gate,
    ) {}

    public function edit(Request $request, Card $card): Response
    {
        $this->guardProfileMode($request, $card);
        $unlocked = $this->isUnlocked($request, $card);

        return $this->noStore(response()->view('public.nfc-profile-edit', [
            'card' => $card,
            'profile' => $card->profile,
            'links' => $unlocked ? $card->activeNfcLinks()->get() : collect(),
            'unlocked' => $unlocked,
        ]));
    }

    public function unlock(Request $request, Card $card): RedirectResponse
    {
        $this->guardProfileMode($request, $card);
        $key = $this->throttleKey($request, $card);

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            return $this->noStore(back()->withErrors([
                'activation_pin' => 'Too many incorrect attempts. Please try again in '
                    .ceil(RateLimiter::availableIn($key) / 60).' minute(s).',
            ]));
        }

        $rules = ['activation_pin' => ['required', 'string', 'digits:6']];

        // First unlock on a universal-PIN card also personalizes the PIN:
        // approved product rule — after providing the activation PIN the
        // owner must provide their own.
        if (! $card->pinIsPersonalized()) {
            $rules += $this->cards->newPinRules();
        }

        $data = $request->validate($rules, $this->cards->newPinMessages(), [
            'activation_pin' => 'activation PIN',
            'new_pin' => 'new PIN',
        ]);

        if (! $this->cards->verifyPin($card, $data['activation_pin'])) {
            RateLimiter::hit($key, self::DECAY_SECONDS);

            return $this->noStore(back()
                ->withErrors(['activation_pin' => 'That activation PIN is not correct.'])
                ->withInput($request->except(['activation_pin', 'new_pin', 'new_pin_confirmation'])));
        }

        RateLimiter::clear($key);

        if (isset($data['new_pin'])) {
            $this->cards->personalizePin($card, $data['new_pin']);
        }

        $this->gate->unlock($request, $card);

        return $this->noStore(redirect()->route('nfc.profile.edit', $card->public_id));
    }

    public function update(Request $request, Card $card): RedirectResponse
    {
        $this->guardProfileMode($request, $card);
        abort_unless($this->isUnlocked($request, $card), 403);

        $rawLinks = $request->input('links', []);

        if (is_array($rawLinks)) {
            $normalizedLinks = [];

            foreach ($rawLinks as $link) {
                if (! is_array($link)) {
                    $normalizedLinks[] = $link;

                    continue;
                }

                $title = $link['title'] ?? null;
                $url = $link['url'] ?? null;
                $icon = $link['icon'] ?? null;
                $title = is_string($title) ? $this->singleLineOrNull($title) : $title;
                $url = is_string($url) ? trim($url) : $url;
                $icon = is_string($icon) ? (trim($icon) ?: null) : null;

                if (($title === null || $title === '') && ($url === null || $url === '')) {
                    continue;
                }

                $normalizedLinks[] = ['title' => $title, 'url' => $url, 'icon' => $icon];
            }

            $request->merge(['links' => $normalizedLinks]);
        }

        $data = $request->validate([
            'display_name' => ['nullable', 'string', 'max:120'],
            'headline' => ['nullable', 'string', 'max:200'],
            'username' => ['nullable', 'string', 'max:60'],
            'location' => ['nullable', 'string', 'max:100'],
            'tagline' => ['nullable', 'string', 'max:200'],
            'phone' => ['nullable', 'string', new SafePhoneNumber()],
            'email' => ['nullable', 'email:rfc', 'max:190'],
            'avatar' => ['nullable', 'file', 'image', 'mimes:jpeg,png,webp', 'max:5120', 'dimensions:max_width=8000,max_height=8000'],
            'remove_avatar' => ['nullable', 'boolean'],
            'links' => ['array', 'max:12'],
            'links.*' => ['array'],
            'links.*.title' => ['required', 'string', 'max:120'],
            'links.*.url' => ['required', 'string', 'max:500', new SafeExternalUrl()],
            'links.*.icon' => ['nullable', 'string', \Illuminate\Validation\Rule::in(array_keys(LinkPlatform::iconChoices()))],
        ]);

        $newPath = null;
        $stored = null;

        if ($avatar = $request->file('avatar')) {
            try {
                $stored = $this->images->storeWithInspection(
                    $avatar,
                    'profile-avatars',
                    512,
                    fn (\GdImage $image): string => $this->colors->extract($image)
                );
            } catch (UnreadableImageException $exception) {
                return $this->noStore(back()
                    ->withErrors(['avatar' => $exception->getMessage()])
                    ->withInput($request->except('avatar')));
            }

            $newPath = $stored['path'];
        }

        try {
            DB::transaction(function () use ($request, $card, $data, $stored): void {
                $lockedCard = Card::query()->whereKey($card->id)->lockForUpdate()->firstOrFail();
                $this->guardProfileMode($request, $lockedCard);
                $lockedCard->load(['profile', 'nfcLinks']);

                $snapshot = $this->snapshot($lockedCard);
                $profile = $lockedCard->profile ?: $lockedCard->profile()->make();

                if ($stored !== null) {
                    $profile->forceFill([
                        'avatar_path' => $stored['path'],
                        'brand_color' => $stored['inspection'],
                    ]);
                } elseif ((bool) ($data['remove_avatar'] ?? false)) {
                    $profile->forceFill(['avatar_path' => null, 'brand_color' => null]);
                }

                $profile->fill([
                    'display_name' => $this->singleLineOrNull($data['display_name'] ?? null),
                    'headline' => $this->singleLineOrNull($data['headline'] ?? null),
                    'username' => $this->singleLineOrNull($data['username'] ?? null),
                    'location' => $this->singleLineOrNull($data['location'] ?? null),
                    'tagline' => $this->singleLineOrNull($data['tagline'] ?? null),
                    'phone' => $this->singleLineOrNull($data['phone'] ?? null),
                    'email' => $this->singleLineOrNull($data['email'] ?? null),
                ]);

                $lockedCard->profileEdits()->create([
                    'ip_address' => (string) $request->ip(),
                    'user_agent' => $this->limitedUserAgent($request),
                    'snapshot' => $snapshot,
                ]);

                if ($profile->exists) {
                    $profile->save();
                } else {
                    $lockedCard->profile()->save($profile);
                }

                // Saving from the setup chooser commits the choice: the card
                // becomes a Profile card. A no-op for cards already in it.
                if ($lockedCard->nfcMode() !== NfcMode::Profile) {
                    $lockedCard->forceFill([
                        'nfc_mode'          => NfcMode::Profile,
                        'nfc_configured_at' => now(),
                    ])->save();
                }

                $lockedCard->nfcLinks()->delete();

                foreach ($data['links'] as $order => $link) {
                    $lockedCard->nfcLinks()->create([
                        'title' => $link['title'],
                        'url' => $link['url'],
                        'icon' => $link['icon'] ?? null,
                        'sort_order' => $order,
                        'is_active' => true,
                    ]);
                }
            });
        } catch (Throwable $exception) {
            if ($newPath !== null) {
                $this->photos->forget($newPath, $card);
            }

            throw $exception;
        }

        return $this->noStore(redirect()
            ->route('nfc.profile.edit', $card->public_id)
            ->with('status', 'Your profile has been updated.'));
    }

    /**
     * The editor serves three audiences: the owner of a live Profile card,
     * the owner of an UNCONFIGURED card who picked "Profile page" on the
     * setup chooser, and an owner who unlocked the manage page to switch a
     * live card of another mode over to a profile. A live other-mode card
     * with NO owner unlock stays off limits — its content must not be
     * switched out from under it by a bare public URL.
     */
    private function guardProfileMode(Request $request, Card $card): void
    {
        abort_if(
            $card->nfcMode() !== NfcMode::Profile
                && $card->nfcModeIsConfigured()
                && ! $this->gate->isUnlocked($request, $card),
            404
        );
    }

    private function isUnlocked(Request $request, Card $card): bool
    {
        return $this->gate->isUnlocked($request, $card);
    }

    private function throttleKey(Request $request, Card $card): string
    {
        return 'nfc-profile-edit|'.$card->public_id.'|'.$request->ip();
    }

    private function snapshot(Card $card): array
    {
        $profile = $card->profile;

        return [
            'profile' => $profile === null ? null : [
                'display_name' => $profile->display_name,
                'headline' => $profile->headline,
                'username' => $profile->username,
                'location' => $profile->location,
                'tagline' => $profile->tagline,
                'phone' => $profile->phone,
                'email' => $profile->email,
                'welcome_eyebrow' => $profile->welcome_eyebrow,
                'welcome_heading' => $profile->welcome_heading,
                'welcome_body' => $profile->welcome_body,
                'welcome_cta_label' => $profile->welcome_cta_label,
                'welcome_cta_url' => $profile->welcome_cta_url,
                'avatar_path' => $profile->avatar_path,
                'brand_color' => $profile->brand_color,
            ],
            'links' => $card->nfcLinks
                ->sortBy([['sort_order', 'asc'], ['id', 'asc']])
                ->map(fn ($link): array => [
                    'title' => $link->title,
                    'url' => $link->url,
                    'icon' => $link->icon,
                    'sort_order' => $link->sort_order,
                    'is_active' => $link->is_active,
                ])
                ->values()
                ->all(),
        ];
    }

    private function singleLineOrNull(?string $value): ?string
    {
        $valid = mb_convert_encoding((string) $value, 'UTF-8', 'UTF-8');
        $clean = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $valid) ?? '');

        return $clean === '' ? null : $clean;
    }

    private function limitedUserAgent(Request $request): ?string
    {
        $agent = trim((string) $request->userAgent());

        return $agent === '' ? null : mb_substr($agent, 0, 500);
    }

    private function noStore(Response|RedirectResponse $response): Response|RedirectResponse
    {
        return $response
            ->header('Cache-Control', 'no-store, private, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
