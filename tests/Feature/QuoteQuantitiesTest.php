<?php

use App\Models\Client;
use App\Models\Project;
use App\Models\Quote;
use Laravel\Sanctum\Sanctum;

/** Devis à deux sections visibles et une masquée, avec des forfaits ; renvoie [id du devis, id des sections]. */
function quantitiesQuote(): array
{
    $client = Client::factory()->create();
    $quote = test()->postJson('/api/v1/quotes', ['client_id' => $client->id, 'title' => 'Alarme incendie'])->assertCreated()->json('data');
    $first = $quote['sections'][0]['id'];
    test()->patchJson("/api/v1/quote-sections/{$first}", ['title' => 'Bâtiment A'])->assertOk();
    $second = test()->postJson("/api/v1/quotes/{$quote['id']}/sections", ['title' => 'Bâtiment B'])->json('data.sections.1.id');
    $hidden = test()->postJson("/api/v1/quotes/{$quote['id']}/sections", ['title' => 'Option'])->json('data.sections.2.id');
    test()->patchJson("/api/v1/quote-sections/{$hidden}", ['is_visible' => false])->assertOk();

    $line = fn (int $section, array $data) => test()->postJson("/api/v1/quote-sections/{$section}/lines", ['unit_cost' => 1000, ...$data])->assertOk();
    $line($first, ['designation' => 'Détecteur optique', 'reference' => 'DO-100', 'category' => 'device', 'quantity' => 12]);
    $line($first, ['designation' => 'Câble 2 paires', 'category' => 'cable', 'unit' => 'm', 'quantity' => 150.5]);
    $line($first, ['designation' => 'Forfait installation', 'category' => 'service', 'quantity' => 1]);
    $line($second, ['designation' => 'Détecteur optique', 'reference' => 'DO-100', 'category' => 'device', 'quantity' => 8]);
    $line($second, ['designation' => 'Centrale 4 zones', 'category' => 'central', 'quantity' => 1]);
    $line($second, ['designation' => 'Mise en service', 'category' => 'service', 'quantity' => 2]);
    $line($hidden, ['designation' => 'Sirène extérieure', 'category' => 'device', 'quantity' => 3]);

    return [$quote['id'], $first, $second, $hidden];
}

it('exporte le quantitatif de tout le devis en CSV, sans forfaits, sans prix, sans section masquée, avec un récapitulatif', function () {
    Sanctum::actingAs(userWithRole('sales'));
    [$quoteId] = quantitiesQuote();

    $response = $this->get("/api/v1/quotes/{$quoteId}/quantities?format=csv")->assertOk();
    $csv = $response->streamedContent();

    expect($response->headers->get('content-type'))->toContain('text/csv')
        ->and($response->headers->get('content-disposition'))->toContain('Quantitatif DEV-')
        ->and($csv)->toStartWith("\xEF\xBB\xBF")
        ->toContain('Section;Catégorie;Désignation;Référence;Unité;Quantité')
        ->toContain('"Bâtiment A";Organe;"Détecteur optique";DO-100;;12')
        ->toContain('150,5')
        ->toContain('Récapitulatif;Centrale;"Centrale 4 zones";;;1')
        ->toContain('Récapitulatif;Organe;"Détecteur optique";DO-100;;20')
        ->not->toContain('Forfait')->not->toContain('Mise en service')
        ->not->toContain('Sirène')->not->toContain('1000');
});

it('exporte le quantitatif d\'une seule section, même masquée, sans récapitulatif', function () {
    Sanctum::actingAs(userWithRole('sales'));
    [$quoteId, , $second, $hidden] = quantitiesQuote();

    $csv = $this->get("/api/v1/quotes/{$quoteId}/quantities?format=csv&section_id={$second}")->assertOk()->streamedContent();
    expect($csv)->toContain('"Centrale 4 zones"')->toContain(';8')
        ->not->toContain('Bâtiment A')->not->toContain('Récapitulatif')->not->toContain('Mise en service');

    $this->get("/api/v1/quotes/{$quoteId}/quantities?format=csv&section_id={$hidden}")->assertOk();
});

it('produit le quantitatif en PDF, pour tout le devis ou une section', function () {
    Sanctum::actingAs(userWithRole('sales'));
    [$quoteId, $first] = quantitiesQuote();

    foreach (['', "?section_id={$first}", '?neutral=1&inline=1'] as $query) {
        $response = $this->get("/api/v1/quotes/{$quoteId}/quantities{$query}")->assertOk();
        expect(substr($response->getContent(), 0, 4))->toBe('%PDF');
    }
});

it('refuse une section d\'un autre devis', function () {
    Sanctum::actingAs(userWithRole('sales'));
    [$quoteId] = quantitiesQuote();
    [, $otherSection] = quantitiesQuote();

    $this->get("/api/v1/quotes/{$quoteId}/quantities?section_id={$otherSection}")->assertNotFound();
    $this->getJson("/api/v1/quotes/{$quoteId}/quantities?format=xlsx")->assertUnprocessable();
});

it('ouvre le quantitatif au chef de projet du devis, pas à un autre chef de projet', function () {
    $chef = userWithRole('project_manager');
    $other = userWithRole('project_manager');
    Sanctum::actingAs(userWithRole('sales'));
    [$quoteId] = quantitiesQuote();
    Quote::find($quoteId)->forceFill(['project_id' => Project::factory()->create(['manager_id' => $chef->id])->id])->save();

    Sanctum::actingAs($chef);
    $this->get("/api/v1/quotes/{$quoteId}/quantities?format=csv")->assertOk();

    Sanctum::actingAs($other);
    $this->get("/api/v1/quotes/{$quoteId}/quantities?format=csv")->assertForbidden();
});
