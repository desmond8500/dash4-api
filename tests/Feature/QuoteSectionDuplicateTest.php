<?php

use App\Models\Client;
use App\Models\Quote;
use Laravel\Sanctum\Sanctum;

/** Devis avec une section « Bâtiment A » (texte de proposition, 2 lignes dont une retirée du quantitatif) puis « Bâtiment B ». */
function sectionDuplicateQuote(string $title = 'Alarme'): array
{
    $client = Client::factory()->create();
    $quote = test()->postJson('/api/v1/quotes', ['client_id' => $client->id, 'title' => $title])->assertCreated()->json('data');
    $a = $quote['sections'][0]['id'];
    test()->patchJson("/api/v1/quote-sections/{$a}", ['title' => 'Bâtiment A', 'proposal' => 'Détection **incendie**.'])->assertOk();
    test()->postJson("/api/v1/quote-sections/{$a}/lines", ['designation' => 'Détecteur', 'category' => 'device', 'quantity' => 12, 'unit_cost' => 10000, 'coefficient' => 1.5, 'comment' => 'Plafond'])->assertOk();
    $cable = test()->postJson("/api/v1/quote-sections/{$a}/lines", ['designation' => 'Câble', 'category' => 'cable', 'unit' => 'm', 'quantity' => 100, 'unit_cost' => 300])->assertOk()
        ->json('data.sections.0.lines.1.id');
    test()->postJson("/api/v1/quotes/{$quote['id']}/quantities/lines", ['hidden' => [$cable]])->assertOk();
    test()->postJson("/api/v1/quotes/{$quote['id']}/sections", ['title' => 'Bâtiment B']);

    return [$quote['id'], $a];
}

it('duplique une section et son contenu juste après elle, dans le même devis', function () {
    Sanctum::actingAs(userWithRole('sales'));
    [$quoteId, $a] = sectionDuplicateQuote();
    $before = Quote::find($quoteId)->total;

    $response = $this->postJson("/api/v1/quote-sections/{$a}/duplicate")->assertOk();
    $sections = $response->json('data.sections');

    expect(array_column($sections, 'title'))->toBe(['Bâtiment A', 'Bâtiment A (copie)', 'Bâtiment B'])
        ->and($sections[1]['proposal'])->toBe('Détection **incendie**.')
        ->and(array_column($sections[1]['lines'], 'designation'))->toBe(['Détecteur', 'Câble'])
        ->and($sections[1]['lines'][0]['comment'])->toBe('Plafond')
        ->and($sections[1]['lines'][0]['quantity'])->toEqual(12)
        ->and($sections[1]['lines'][1]['show_in_quantities'])->toBeFalse()
        ->and($response->json('meta.copied_to.0.section_id'))->toBe($sections[1]['id'])
        ->and((float) Quote::find($quoteId)->total)->toBe((float) $before * 2);
});

it('copie une section dans d\'autres devis modifiables, à la fin et sous le même titre', function () {
    Sanctum::actingAs(userWithRole('sales'));
    [$sourceId, $a] = sectionDuplicateQuote();
    [$otherId] = sectionDuplicateQuote('Vidéo');
    [$thirdId] = sectionDuplicateQuote('Contrôle d\'accès');

    $this->postJson("/api/v1/quote-sections/{$a}/duplicate", ['quote_ids' => [$otherId, $thirdId]])->assertOk()
        ->assertJsonCount(2, 'meta.copied_to')
        ->assertJsonPath('meta.copied_to.0.quote_id', $otherId)
        ->assertJsonCount(2, 'data.sections'); // le devis d'origine ne change pas

    $other = $this->getJson("/api/v1/quotes/{$otherId}")->json('data');
    expect(array_column($other['sections'], 'title'))->toBe(['Bâtiment A', 'Bâtiment B', 'Bâtiment A'])
        ->and(end($other['sections'])['lines'])->toHaveCount(2)
        ->and((float) Quote::find($otherId)->total)->toBe((float) Quote::find($sourceId)->total * 2);
});

it('refuse de copier dans un devis accepté ou que l\'on ne peut pas modifier', function () {
    Sanctum::actingAs(userWithRole('sales'));
    [, $a] = sectionDuplicateQuote();
    [$acceptedId] = sectionDuplicateQuote('Accepté');
    $this->postJson("/api/v1/quotes/{$acceptedId}/status", ['status' => 'accepted'])->assertOk();

    $this->postJson("/api/v1/quote-sections/{$a}/duplicate", ['quote_ids' => [$acceptedId]])->assertStatus(422);
    $this->postJson("/api/v1/quote-sections/{$a}/duplicate", ['quote_ids' => [999999]])->assertNotFound();

    // Un chef de projet ne copie pas une section d'un devis qu'il ne voit pas
    Sanctum::actingAs(userWithRole('project_manager'));
    $this->postJson("/api/v1/quote-sections/{$a}/duplicate")->assertForbidden();
});

it('garde le texte de la proposition technique quand on duplique tout le devis', function () {
    Sanctum::actingAs(userWithRole('sales'));
    [$quoteId] = sectionDuplicateQuote();

    $copy = $this->postJson("/api/v1/quotes/{$quoteId}/duplicate")->assertCreated()->json('data');
    expect($copy['sections'][0]['proposal'])->toBe('Détection **incendie**.');
});
