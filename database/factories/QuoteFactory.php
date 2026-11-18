<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\Quote;
use App\Support\DocumentNumber;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Quote> */
class QuoteFactory extends Factory
{
    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'title' => 'Installation '.fake()->words(2, true),
            'issued_at' => now()->toDateString(),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Quote $quote) {
            $quote->number ??= DocumentNumber::next('quote');
        });
    }
}
