<?php

namespace Database\Factories;

use App\Models\Article;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Article> */
class ArticleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'designation' => 'Caméra '.fake()->unique()->bothify('IP-####'),
            'reference' => fake()->bothify('REF-####'),
            'category' => 'device',
            'unit' => 'u',
            'unit_cost' => 50000,
            'coefficient' => 1.5,
        ];
    }

    /** Quantité posée directement (sans mouvement), pour les tests. */
    public function inStock(float $quantity, ?float $min = null): static
    {
        return $this->state(fn () => ['min_quantity' => $min])->afterCreating(fn (Article $a) => $a->forceFill(['quantity' => $quantity])->save());
    }
}
