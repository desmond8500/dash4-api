<?php

namespace Database\Factories;

use App\Models\TechAccessRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TechAccessRequest> */
class TechAccessRequestFactory extends Factory
{
    public function definition(): array
    {
        return ['user_id' => User::factory(), 'subject' => 'Code du local technique'];
    }
}
