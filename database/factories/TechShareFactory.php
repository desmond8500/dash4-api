<?php

namespace Database\Factories;

use App\Models\TechShare;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TechShare> */
class TechShareFactory extends Factory
{
    public function definition(): array
    {
        return ['title' => 'Code portail '.fake()->word(), 'content' => 'Code : 1234', 'expires_at' => now()->addDay()];
    }
}
