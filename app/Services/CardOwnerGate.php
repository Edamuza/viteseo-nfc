<?php

namespace App\Services;

use App\Models\Card;
use Illuminate\Http\Request;

/**
 * One PIN unlock, one session, three doors.
 *
 * The profile editor, the manage page, and the mode-switch setup forms all
 * gate on "the person at this phone proved they know this card's PIN". That
 * proof lives here as a single per-card session timestamp, so unlocking on
 * the manage page also opens the setup forms it links to — asking for the
 * PIN again on every hop would train owners to type it reflexively.
 */
class CardOwnerGate
{
    /** Seconds an unlock stays valid; matches the profile editor's original. */
    public const UNLOCK_SECONDS = 1800;

    public function unlock(Request $request, Card $card): void
    {
        $request->session()->put($this->sessionKey($card), now()->timestamp);
    }

    public function isUnlocked(Request $request, Card $card): bool
    {
        $key = $this->sessionKey($card);
        $timestamp = (int) $request->session()->get($key, 0);

        if ($timestamp < now()->timestamp - self::UNLOCK_SECONDS) {
            $request->session()->forget($key);

            return false;
        }

        return $timestamp > 0;
    }

    private function sessionKey(Card $card): string
    {
        return 'nfc-owner:'.$card->public_id;
    }
}
