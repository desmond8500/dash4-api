<?php

namespace Database\Factories;

use App\Enums\ClientStatus;
use App\Enums\ClientType;
use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Client> */
class ClientFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'type' => ClientType::Company,
            'status' => ClientStatus::Active,
            'address' => fake()->address(),
            'is_favorite' => false,
        ];
    }
}
