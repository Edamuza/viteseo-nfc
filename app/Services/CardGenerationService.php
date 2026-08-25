<?php

namespace App\Services;

use App\Models\Card;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * The single place cards are created.
 *
 * Seeding, the artisan command and every admin "+N" button all call this, so
 * card numbering, public_id generation and PIN handling can never drift apart.
 */
class CardGenerationService
{
    /**
     * Crockford-style Base32: no 0/O or 1/I confusion when someone reads a
     * public_id off a printed card and types it in.
     */
    public const ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

    public const PUBLIC_ID_LENGTH = 8;   // 32^8 ~= 1.1e12 keyspace

    public const MAX_PER_REQUEST = 500;

    /**
     * Universal activation PIN.
     *
     * DELIBERATE SECURITY TRADEOFF: every card shares this PIN. It is an
     * anti-accident guard (stopping a stray scan from configuring a card), not
     * an authentication secret. Rate limiting on activation attempts is
     * therefore still enforced - see the public QR/NFC controllers.
     */
    public const UNIVERSAL_PIN = '000000';

    private const COLLISION_RETRIES = 12;

    /**
     * @return Collection<int, Card>
     */
    public function generate(int $quantity): Collection
    {
        if ($quantity < 1 || $quantity > self::MAX_PER_REQUEST) {
            throw new RuntimeException(
                'Quantity must be between 1 and '.self::MAX_PER_REQUEST.'.'
            );
        }

        return DB::transaction(function () use ($quantity) {
            // Lock the highest existing row so two concurrent generations cannot
            // both decide the next card number is the same.
            $last = Card::query()->lockForUpdate()->orderByDesc('id')->first();

            // Parse ONLY the trailing digits. FILTER_SANITIZE_NUMBER_INT would keep
            // the hyphen in "CARD-0050" and yield -50, which collides on the next batch.
            $next = 1;

            if ($last && preg_match('/(\d+)$/', $last->card_number, $m)) {
                $next = ((int) $m[1]) + 1;
            }

            $created = collect();

            for ($i = 0; $i < $quantity; $i++) {
                $pin = $this->generatePin();

                $created->push(Card::create([
                    'card_number'              => $this->formatCardNumber($next + $i),
                    'public_id'                => $this->generateUniquePublicId(),
                    'activation_pin_hash'      => Hash::make($pin),
                    'activation_pin_encrypted' => $pin,   // 'encrypted' cast handles this
                    'qr_destination'           => null,
                    'qr_configured_at'         => null,
                    'nfc_configured_at'        => null,
                ]));
            }

            return $created;
        });
    }

    public function formatCardNumber(int $n): string
    {
        return 'CARD-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Returns the universal activation PIN.
     *
     * Previously this generated a random 6-digit PIN. Cards now all share
     * UNIVERSAL_PIN by product decision, so there is no random PIN generation
     * anywhere in the system.
     */
    public function generatePin(): string
    {
        return self::UNIVERSAL_PIN;
    }

    public function generatePublicId(): string
    {
        $max = strlen(self::ALPHABET) - 1;
        $out = '';

        for ($i = 0; $i < self::PUBLIC_ID_LENGTH; $i++) {
            $out .= self::ALPHABET[random_int(0, $max)];
        }

        return $out;
    }

    /**
     * The unique index on public_id is the real guarantee; this loop just keeps
     * us from hitting it in normal operation.
     */
    public function generateUniquePublicId(): string
    {
        for ($attempt = 0; $attempt < self::COLLISION_RETRIES; $attempt++) {
            $candidate = $this->generatePublicId();

            if (! Card::where('public_id', $candidate)->exists()) {
                return $candidate;
            }
        }

        throw new RuntimeException('Could not generate a unique public_id after '.self::COLLISION_RETRIES.' attempts.');
    }

    /**
     * Re-applies the universal PIN to a card.
     *
     * Writes through the Eloquent model so the `encrypted` cast produces a
     * valid ciphertext - never raw SQL into that column.
     */
    public function applyUniversalPin(Card $card): string
    {
        $pin = self::UNIVERSAL_PIN;

        $card->forceFill([
            'activation_pin_hash'      => Hash::make($pin),
            'activation_pin_encrypted' => $pin,
            // Back to the universal PIN means back to "not personalized":
            // the next setup or unlock will demand a personal PIN again.
            'pin_personalized_at'      => null,
        ])->save();

        return $pin;
    }

    /**
     * The owner's own PIN, set during setup or from the manage page. Stored
     * exactly like the universal one — hash for verification, encrypted for
     * the admin reveal — so verifyPin() and revealPin() need no changes.
     */
    public function personalizePin(Card $card, string $pin): void
    {
        $card->forceFill([
            'activation_pin_hash'      => Hash::make($pin),
            'activation_pin_encrypted' => $pin,
            'pin_personalized_at'      => now(),
        ])->save();
    }

    /**
     * Validation for the owner's personal PIN. Shared by every flow that
     * collects one — the three setup forms, the profile editor's unlock,
     * and the manage page — so the rules cannot drift apart.
     *
     * @return array<string, array<int, mixed>>
     */
    public function newPinRules(): array
    {
        return [
            'new_pin' => [
                'required', 'string', 'digits:6', 'confirmed',
                \Illuminate\Validation\Rule::notIn([self::UNIVERSAL_PIN]),
            ],
        ];
    }

    /** @return array<string, string> */
    public function newPinMessages(): array
    {
        return [
            'new_pin.not_in' => 'Choose your own PIN — the default '.self::UNIVERSAL_PIN.' is not allowed.',
            'new_pin.confirmed' => 'The new PIN and its confirmation do not match.',
        ];
    }

    public function revealPin(Card $card): string
    {
        return (string) $card->activation_pin_encrypted;
    }

    public function verifyPin(Card $card, string $candidate): bool
    {
        return Hash::check($candidate, $card->activation_pin_hash);
    }
}
