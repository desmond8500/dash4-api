<?php

namespace Database\Factories;

use App\Models\ClientRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ClientRequest> */
class ClientRequestFactory extends Factory
{
    public function definition(): array
    {
        return [
            'body' => fake()->sentence(8),
            'source' => 'phone',
            'status' => 'new',
            'priority' => 'normal',
        ];
    }
}
