<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\UnreadableImageException;
use App\Http\Controllers\Controller;
use App\Models\Card;
use App\Rules\SafeExternalUrl;
use App\Services\CardResetService;
use App\Services\DominantColor;
use App\Services\ImageIngest;
use App\Services\PublicImageStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Throwable;

class CardProfileController extends Controller
{
    public function __construct(
        private readonly ImageIngest $images,
        private readonly DominantColor $colors,
        private readonly PublicImageStorage $photos,
        private readonly CardResetService $reset,
    ) {}

    public function update(Request $request, Card $card): RedirectResponse
    {
        $data = $request->validate([
            'display_name' => ['nullable', 'string', 'max:120'],
            'headline' => ['nullable', 'string', 'max:200'],
            'username' => ['nullable', 'string', 'max:60'],
            'location' => ['nullable', 'string', 'max:100'],
            'tagline' => ['nullable', 'string', 'max:200'],
            'welcome_eyebrow' => ['nullable', 'string', 'max:60'],
            'welcome_heading' => ['nullable', 'string', 'max:200'],
            'welcome_body' => ['nullable', 'string', 'max:600'],
            'welcome_cta_label' => ['nullable', 'string', 'max:60'],
            'welcome_cta_url' => ['nullable', 'string', 'max:500', new SafeExternalUrl()],
            'avatar' => ['nullable', 'file', 'image', 'mimes:jpeg,png,webp', 'max:2048', 'dimensions:max_width=4000,max_height=4000'],
        ]);

        $clean = [];

        foreach (Arr::except($data, ['avatar', 'welcome_body']) as $key => $value) {
            $clean[$key] = $this->singleLineOrNull(is_string($value) ? $value : null);
        }
        if (array_key_exists('welcome_body', $data)) {
            $clean['welcome_body'] = $this->multilineOrNull($data['welcome_body']);
        }
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
                return back()
                    ->withErrors(['avatar' => $exception->getMessage()])
                    ->withInput($request->except('avatar'));
            }

            $newPath = $stored['path'];
        }

        try {
            $result = DB::transaction(function () use ($card, $clean, $stored): array {
                $lockedCard = Card::query()->whereKey($card->id)->lockForUpdate()->firstOrFail();
                $profile = $lockedCard->profile()->first() ?: $lockedCard->profile()->make();
                $oldPath = null;

                $profile->fill($clean);

                if ($stored !== null) {
                    $oldPath = $profile->avatar_path;
                    $profile->forceFill([
                        'avatar_path' => $stored['path'],
                        'brand_color' => $stored['inspection'],
                    ]);
                }

                if ($profile->exists) {
                    $profile->save();
                } else {
                    $lockedCard->profile()->save($profile);
                }

                return [
                    'old_path' => $oldPath,
                    'meaningful' => $profile->isMeaningful(),
                ];
            });
        } catch (Throwable $exception) {
            if ($newPath !== null) {
                $this->photos->forget($newPath, $card);
            }

            throw $exception;
        }

        if ($newPath !== null) {
            $this->forgetAvatarUnlessRetained($result['old_path'], $card);
        }

        if (! $result['meaningful']) {
            return back()->with('status', 'Saved. Add a display name before this card can display a profile.');
        }

        return back()->with('status', 'Profile details saved.');
    }

    public function destroyAvatar(Card $card): RedirectResponse
    {
        $path = DB::transaction(function () use ($card): ?string {
            $lockedCard = Card::query()->whereKey($card->id)->lockForUpdate()->firstOrFail();
            $profile = $lockedCard->profile()->first();

            if ($profile === null) {
                return null;
            }

            $path = $profile->avatar_path;
            $profile->forceFill(['avatar_path' => null, 'brand_color' => null])->save();

            return $path;
        });

        if ($path !== null) {
            $this->forgetAvatarUnlessRetained($path, $card);
        }

        return back()->with('status', 'Profile avatar removed.');
    }

    public function destroy(Card $card): RedirectResponse
    {
        $this->reset->deleteProfile($card);

        return back()->with('status', 'Profile details deleted.');
    }

    public function revert(Card $card): RedirectResponse
    {
        $result = DB::transaction(function () use ($card): ?array {
            $lockedCard = Card::query()->whereKey($card->id)->lockForUpdate()->firstOrFail();
            $edit = $lockedCard->profileEdits()->first();

            if ($edit === null) {
                return null;
            }

            $snapshot = (array) $edit->snapshot;
            $currentAvatar = $lockedCard->profile()->value('avatar_path');
            $profileData = $snapshot['profile'] ?? null;
            $restoredAvatar = null;

            if ($profileData === null) {
                $lockedCard->profile()->delete();
            } else {
                $profileData = Arr::only((array) $profileData, [
                    'display_name', 'headline', 'username', 'location', 'tagline',
                    'welcome_eyebrow', 'welcome_heading', 'welcome_body',
                    'welcome_cta_label', 'welcome_cta_url', 'avatar_path', 'brand_color',
                ]);
                $profile = $lockedCard->profile()->first() ?: $lockedCard->profile()->make();
                $profile->fill(Arr::except($profileData, ['avatar_path', 'brand_color']));
                $profile->forceFill(Arr::only($profileData, ['avatar_path', 'brand_color']));

                if ($profile->exists) {
                    $profile->save();
                } else {
                    $lockedCard->profile()->save($profile);
                }

                $restoredAvatar = $profile->avatar_path;
            }

            $lockedCard->nfcLinks()->delete();

            foreach ((array) ($snapshot['links'] ?? []) as $link) {
                $link = Arr::only((array) $link, ['title', 'url', 'sort_order', 'is_active']);
                $lockedCard->nfcLinks()->create([
                    'title' => $link['title'] ?? null,
                    'url' => $link['url'] ?? null,
                    'sort_order' => (int) ($link['sort_order'] ?? 0),
                    'is_active' => (bool) ($link['is_active'] ?? false),
                ]);
            }

            $edit->delete();

            return [
                'current_avatar' => $currentAvatar,
                'restored_avatar' => $restoredAvatar,
            ];
        });

        if ($result === null) {
            return back()->with('status', 'There is no client profile edit to revert.');
        }

        if ($result['current_avatar'] !== $result['restored_avatar']) {
            $this->forgetAvatarUnlessRetained($result['current_avatar'], $card);
        }

        return back()->with('status', 'Latest client profile edit reverted.');
    }

    private function forgetAvatarUnlessRetained(?string $path, Card $card): void
    {
        if ((string) $path === '') {
            return;
        }

        DB::transaction(function () use ($path, $card): void {
            $lockedCard = Card::query()->whereKey($card->id)->lockForUpdate()->firstOrFail();
            $isCurrent = $lockedCard->profile()->value('avatar_path') === $path;
            $isSnapshotted = $lockedCard->profileEdits()
                ->get()
                ->contains(fn ($edit): bool => data_get($edit->snapshot, 'profile.avatar_path') === $path);

            if (! $isCurrent && ! $isSnapshotted) {
                // The mutation that orphaned this path already committed. This
                // short cleanup transaction holds the same card lock so no
                // concurrent revert can make the path current between this
                // final retention check and the filesystem delete.
                $this->photos->forget($path, $lockedCard);
            }
        });
    }

    private function singleLineOrNull(?string $value): ?string
    {
        $valid = mb_convert_encoding((string) $value, 'UTF-8', 'UTF-8');
        $clean = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $valid) ?? '');

        return $clean === '' ? null : $clean;
    }

    private function multilineOrNull(?string $value): ?string
    {
        $valid = mb_convert_encoding((string) $value, 'UTF-8', 'UTF-8');
        $valid = str_replace(["\r\n", "\r"], "\n", $valid);
        $clean = trim(preg_replace('/[\x00-\x09\x0B\x0C\x0E-\x1F\x7F]/u', '', $valid) ?? '');

        return $clean === '' ? null : $clean;
    }
}
