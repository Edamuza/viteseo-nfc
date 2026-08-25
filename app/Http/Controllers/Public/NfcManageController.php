<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Card;
use App\Services\CardGenerationService;
use App\Services\CardOwnerGate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The card owner's self-service door: change what the card does (via the
 * setup forms, which honour the same unlock) and change its PIN. PIN-gated
 * with the same throttle discipline as the profile editor; configured cards
 * are exactly who this page is for.
 */
class NfcManageController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    private const DECAY_SECONDS = 900;

    public function __construct(
        private readonly CardGenerationService $cards,
        private readonly CardOwnerGate $gate,
    ) {}

    public function show(Request $request, Card $card): Response
    {
        return $this->noStore(response()->view('public.nfc-manage', [
            'card' => $card,
            'unlocked' => $this->gate->isUnlocked($request, $card),
        ]));
    }

    public function unlock(Request $request, Card $card): RedirectResponse
    {
        $key = $this->throttleKey($request, $card);

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            return $this->noStore(back()->withErrors([
                'activation_pin' => 'Too many incorrect attempts. Please try again in '
                    .ceil(RateLimiter::availableIn($key) / 60).' minute(s).',
            ]));
        }

        $rules = ['activation_pin' => ['required', 'string', 'digits:6']];

        // A card still on the universal PIN gets personalized as part of the
        // unlock: proving you know a PIN every customer was issued is not
        // proof of ownership worth keeping.
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

        return $this->noStore(redirect()->route('nfc.manage', $card->public_id));
    }

    public function changePin(Request $request, Card $card): RedirectResponse
    {
        abort_unless($this->gate->isUnlocked($request, $card), 403);

        $data = $request->validate(
            $this->cards->newPinRules(),
            $this->cards->newPinMessages(),
            ['new_pin' => 'new PIN']
        );

        $this->cards->personalizePin($card, $data['new_pin']);

        return $this->noStore(back()->with('status', 'Your card PIN has been changed.'));
    }

    private function throttleKey(Request $request, Card $card): string
    {
        return 'nfc-manage|'.$card->public_id.'|'.$request->ip();
    }

    private function noStore(Response|RedirectResponse $response): Response|RedirectResponse
    {
        return $response
            ->header('Cache-Control', 'no-store, private, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
