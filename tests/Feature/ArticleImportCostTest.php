<?php

use App\Models\Article;
use App\Support\ImportCost;
use Laravel\Sanctum\Sanctum;

it('calcule le coût en F CFA depuis un prix en euro : × 655,957, + 20 %, + 10 000 F', function () {
    Sanctum::actingAs(userWithRole('manager'));

    $article = $this->postJson('/api/v1/articles', ['designation' => 'Centrale Galaxy', 'foreign_price' => 100, 'foreign_currency' => 'EUR', 'unit_cost' => 5])
        ->assertCreated()
        ->assertJsonPath('data.foreign_price', 100)
        ->assertJsonPath('data.foreign_currency', 'EUR')
        ->json('data');

    // 100 × 655,957 = 65 595,70 ; × 1,2 = 78 714,84 ; + 10 000 = 88 714,84 → 88 715
    expect($article['unit_cost'])->toEqual(88715);
});

it('utilise le taux du dollar, la taxe et les frais de port des paramètres', function () {
    Sanctum::actingAs(userWithRole('admin'));
    $this->patchJson('/api/v1/settings', ['import_costs' => ['usd_rate' => 590, 'shipping' => 15000]])->assertOk()
        ->assertJsonPath('data.import_costs.usd_rate', 590)
        ->assertJsonPath('data.import_costs.tax_rate', 20)
        ->assertJsonPath('data.import_costs.shipping', 15000);

    $article = $this->postJson('/api/v1/articles', ['designation' => 'Détecteur US', 'foreign_price' => 50, 'foreign_currency' => 'USD'])->assertCreated()->json('data');
    // 50 × 590 × 1,2 + 15 000 = 50 400
    expect($article['unit_cost'])->toEqual(50400);

    $this->getJson('/api/v1/articles/import-costs')->assertOk()
        ->assertJsonPath('data.rates.EUR', ImportCost::EUR_RATE)
        ->assertJsonPath('data.rates.USD', 590)
        ->assertJsonPath('data.tax_rate', 20)
        ->assertJsonPath('data.shipping', 15000);
});

it('recalcule le coût quand le prix ou la devise change, et le laisse quand on efface le prix', function () {
    Sanctum::actingAs(userWithRole('manager'));
    $id = $this->postJson('/api/v1/articles', ['designation' => 'Clavier', 'foreign_price' => 100, 'foreign_currency' => 'EUR'])->json('data.id');

    $this->patchJson("/api/v1/articles/{$id}", ['foreign_currency' => 'USD'])->assertOk()->assertJsonPath('data.unit_cost', 82000); // 100 × 600 × 1,2 + 10 000
    $this->patchJson("/api/v1/articles/{$id}", ['foreign_price' => 200])->assertOk()->assertJsonPath('data.unit_cost', 154000);
    $this->patchJson("/api/v1/articles/{$id}", ['foreign_price' => null])->assertOk()
        ->assertJsonPath('data.foreign_price', null)->assertJsonPath('data.foreign_currency', null)->assertJsonPath('data.unit_cost', 154000);

    // Un coût saisi à la main reste possible sans prix en devise
    $this->patchJson("/api/v1/articles/{$id}", ['unit_cost' => 120000])->assertOk()->assertJsonPath('data.unit_cost', 120000);
    expect(Article::find($id)->foreign_price)->toBeNull();
});

it('réserve le prix en devise à qui voit les coûts', function () {
    Sanctum::actingAs(userWithRole('manager'));
    $id = $this->postJson('/api/v1/articles', ['designation' => 'Sirène', 'foreign_price' => 10, 'foreign_currency' => 'EUR'])->json('data.id');

    Sanctum::actingAs(userWithRole('technician'));
    $this->getJson("/api/v1/articles/{$id}")->assertOk()->assertJsonMissingPath('data.foreign_price')->assertJsonMissingPath('data.unit_cost');
    $this->getJson('/api/v1/articles/import-costs')->assertForbidden();
});
