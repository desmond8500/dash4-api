<?php

namespace Database\Factories;

use App\Models\Journal;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Journal> */
class JournalFactory extends Factory
{
    public function definition(): array
    {
        return ['title' => 'Intervention '.fake()->words(2, true), 'date' => now()->toDateString()];
    }
}
