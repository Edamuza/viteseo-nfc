<?php

namespace Database\Factories;

use App\Services\CardGenerationService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

class CardFactory extends Factory
{
    protected $model = \App\Models\Card::class;

    public function definition(): array
    {
        $svc = app(CardGenerationService::class);
        $pin = $svc->generatePin();

        return [
            'card_number'              => 'CARD-'.str_pad((string) $this->faker->unique()->numberBetween(1, 99999), 4, '0', STR_PAD_LEFT),
            'public_id'                => $svc->generatePublicId(),
            'activation_pin_hash'      => Hash::make($pin),
            'activation_pin_encrypted' => $pin,
            'qr_destination'           => null,
            'qr_configured_at'         => null,
            'nfc_configured_at'        => null,
        ];
    }
}
