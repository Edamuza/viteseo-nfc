<?php

use App\Services\CardGenerationService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Move every existing card onto the universal activation PIN.
 *
 * Both columns are rewritten:
 *   - activation_pin_hash      : bcrypt, used for verification
 *   - activation_pin_encrypted : Laravel ciphertext, used for admin reveal
 *
 * Crypt::encryptString() and the model's `encrypted` cast were verified
 * interchangeable in both directions before writing this, so producing the
 * ciphertext here (rather than through the model) is safe and keeps the
 * migration free of model coupling.
 *
 * Idempotent: re-running simply rewrites the same value.
 */
return new class extends Migration
{
    public function up(): void
    {
        $pin = CardGenerationService::UNIVERSAL_PIN;

        DB::table('cards')->orderBy('id')->select('id')->chunk(200, function ($rows) use ($pin) {
            foreach ($rows as $row) {
                DB::table('cards')->where('id', $row->id)->update([
                    'activation_pin_hash'      => Hash::make($pin),
                    'activation_pin_encrypted' => Crypt::encryptString($pin),
                ]);
            }
        });
    }

    /**
     * Not reversible. The previous per-card random PINs were never stored in
     * recoverable plaintext outside the encrypted column this migration
     * overwrites, so there is nothing to restore. A pre-change dump of the
     * cards table was taken at /root/nfc-update-2026-08-21/backups/.
     */
    public function down(): void
    {
        // intentionally empty
    }
};
