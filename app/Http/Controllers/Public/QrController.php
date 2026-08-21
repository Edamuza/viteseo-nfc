<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Card;
use App\Rules\SafeExternalUrl;
use App\Services\CardGenerationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Public QR endpoint. A QR code has exactly ONE destination, ever - it never
 * supports a multi-link page. That is the deliberate difference from NFC.
 */
class QrController extends Controller
{
    public function __construct(private readonly CardGenerationService $cards) {}

    /** GET /q/{public_id} */
    public function show(Card $card): Response|RedirectResponse
    {
        if ($card->qrIsConfigured()) {
            // 302, never 301: a permanent redirect would be cached by browsers
            // and intermediaries forever, defeating admin "Reset QR".
            return $this->noStore(redirect()->away($card->qr_destination, 302));
        }

        return $this->noStore(response()->view('public.qr-setup', [
            'card' => $card,
        ]));
    }

    /** POST /q/{public_id} */
    public function store(Request $request, Card $card): RedirectResponse
    {
        // Already configured: never re-expose the setup form publicly.
        if ($card->qrIsConfigured()) {
            return redirect()->route('qr.show', $card->public_id);
        }

        if ($response = $this->tooManyAttempts($request, $card, 'qr')) {
            return $response;
        }

        $data = $request->validate([
            'activation_pin' => ['required', 'string', 'digits:6'],
            'destination'    => ['required', 'string', new SafeExternalUrl()],
        ], [], ['activation_pin' => 'activation PIN', 'destination' => 'destination URL']);

        if (! $this->cards->verifyPin($card, $data['activation_pin'])) {
            RateLimiter::hit($this->throttleKey($request, $card, 'qr'), 900);

            return back()
                ->withErrors(['activation_pin' => 'That activation PIN is not correct.'])
                ->withInput($request->except('activation_pin'));
        }

        RateLimiter::clear($this->throttleKey($request, $card, 'qr'));

        $card->forceFill([
            'qr_destination'   => trim($data['destination']),
            'qr_configured_at' => now(),
        ])->save();

        return redirect()->route('qr.done', $card->public_id);
    }

    /** GET /q/{public_id}/done - shown once, immediately after setup. */
    public function done(Card $card): Response
    {
        return $this->noStore(response()->view('public.qr-done', ['card' => $card]));
    }

    private function throttleKey(Request $request, Card $card, string $scope): string
    {
        return $scope.'|'.$card->public_id.'|'.$request->ip();
    }

    private function tooManyAttempts(Request $request, Card $card, string $scope): ?RedirectResponse
    {
        $key = $this->throttleKey($request, $card, $scope);

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);

            return back()->withErrors([
                'activation_pin' => 'Too many incorrect attempts. Please try again in '.ceil($seconds / 60).' minute(s).',
            ]);
        }

        return null;
    }

    /**
     * The same permanent URL changes behaviour once configured, so no layer -
     * browser, Cloudflare, or corporate proxy - may cache these responses.
     */
    private function noStore(Response|RedirectResponse $response): Response|RedirectResponse
    {
        return $response
            ->header('Cache-Control', 'no-store, private, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
