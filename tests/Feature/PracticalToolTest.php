<?php

use App\Models\Article;
use App\Models\Brand;
use App\Models\Provider;
use Laravel\Sanctum\Sanctum;

it('liste les outils pratiques, ouverts à tous les comptes, technicien compris', function () {
    Sanctum::actingAs(userWithRole('technician'));

    $this->getJson('/api/v1/practical-tools')->assertOk()
        ->assertJsonPath('data.0.slug', 'galaxy-dimension-text')
        ->assertJsonPath('data.0.name', 'Texte pour centrale Galaxy Dimension')
        ->assertJsonPath('data.0.links', []);
});

it('lie un outil à une marque, un fournisseur et un article, sans doublon, et le retrouve sur leurs fiches', function () {
    Sanctum::actingAs(userWithRole('storekeeper'));
    $brand = Brand::create(['name' => 'Honeywell']);
    $provider = Provider::factory()->create(['name' => 'Sécurité Plus']);
    $article = Article::factory()->create(['designation' => 'Centrale Galaxy Dimension GD-48', 'reference' => 'C048-E1']);

    $this->postJson('/api/v1/practical-tools/galaxy-dimension-text/links', ['type' => 'brands', 'id' => $brand->id])->assertCreated()
        ->assertJsonPath('data.type', 'brands')->assertJsonPath('data.target.name', 'Honeywell');
    $this->postJson('/api/v1/practical-tools/galaxy-dimension-text/links', ['type' => 'providers', 'id' => $provider->id])->assertCreated();
    $link = $this->postJson('/api/v1/practical-tools/galaxy-dimension-text/links', ['type' => 'articles', 'id' => $article->id])->assertCreated()
        ->assertJsonPath('data.target.reference', 'C048-E1')->json('data');
    $this->postJson('/api/v1/practical-tools/galaxy-dimension-text/links', ['type' => 'articles', 'id' => $article->id])->assertOk();

    $this->getJson('/api/v1/practical-tools')->assertJsonCount(3, 'data.0.links');
    $this->getJson("/api/v1/practical-tools/for/articles/{$article->id}")->assertOk()
        ->assertJsonPath('data.0.tool', 'galaxy-dimension-text')
        ->assertJsonPath('data.0.name', 'Texte pour centrale Galaxy Dimension');

    $this->deleteJson("/api/v1/practical-tool-links/{$link['id']}")->assertOk();
    $this->getJson("/api/v1/practical-tools/for/articles/{$article->id}")->assertJsonCount(0, 'data');
});

it('refuse de lier sans droit sur le stock, un outil inconnu ou une fiche inconnue', function () {
    $brand = Brand::create(['name' => 'Honeywell']);

    Sanctum::actingAs(userWithRole('technician'));
    $this->postJson('/api/v1/practical-tools/galaxy-dimension-text/links', ['type' => 'brands', 'id' => $brand->id])->assertForbidden();

    Sanctum::actingAs(userWithRole('storekeeper'));
    $this->postJson('/api/v1/practical-tools/inconnu/links', ['type' => 'brands', 'id' => $brand->id])->assertNotFound();
    $this->postJson('/api/v1/practical-tools/galaxy-dimension-text/links', ['type' => 'clients', 'id' => 1])->assertUnprocessable();
    $this->postJson('/api/v1/practical-tools/galaxy-dimension-text/links', ['type' => 'brands', 'id' => 999999])->assertNotFound();
});
