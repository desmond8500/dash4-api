<?php

use App\Models\Client;
use App\Models\Company;
use Laravel\Sanctum\Sanctum;

it('règle un devis neutre, même accepté, et télécharge son PDF', function () {
    Sanctum::actingAs(userWithRole('sales'));
    $client = Client::factory()->create();
    $quote = $this->postJson('/api/v1/quotes', ['client_id' => $client->id, 'title' => 'Alarme'])->assertCreated()->json('data');
    expect($quote['is_neutral'])->toBeFalse();

    $this->postJson("/api/v1/quotes/{$quote['id']}/status", ['status' => 'accepted'])->assertOk();
    $this->patchJson("/api/v1/quotes/{$quote['id']}", ['is_neutral' => true])->assertOk()->assertJsonPath('data.is_neutral', true);

    foreach (['', '?neutral=0', '?neutral=1'] as $query) {
        $response = $this->get("/api/v1/quotes/{$quote['id']}/pdf{$query}")->assertOk();
        expect(substr($response->getContent(), 0, 4))->toBe('%PDF');
    }
});

it('n\'affiche aucune information de l\'entreprise dans l\'en-tête et le pied d\'un document neutre', function () {
    $company = Company::current();
    $company->update(['name' => 'Yonkou SARL', 'legal_mentions' => 'NINEA 0001']);

    $normal = view('pdf.partials.header_footer', ['company' => $company->refresh(), 'logo' => null, 'bank' => null])->render();
    $neutral = view('pdf.partials.header_footer', ['company' => $company, 'logo' => null, 'bank' => null, 'neutral' => true])->render();

    expect($normal)->toContain('Yonkou SARL')
        ->and($neutral)->not->toContain('Yonkou SARL')->not->toContain('NINEA')->toContain('Page');
});
