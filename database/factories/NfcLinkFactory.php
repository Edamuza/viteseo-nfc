<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class NfcLinkFactory extends Factory
{
    protected $model = \App\Models\NfcLink::class;

    public function definition(): array
    {
        return [
            'title'      => $this->faker->word(),
            'url'        => 'https://example.com/'.$this->faker->word(),
            'sort_order' => 0,
            'is_active'  => true,
        ];
    }
}
