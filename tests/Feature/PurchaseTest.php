<?php

use App\Models\Article;
use App\Models\Provider;
use App\Models\Purchase;
use Laravel\Sanctum\Sanctum;

it('crée une commande fournisseur numérotée, avec des lignes reprises du stock', function () {
    Sanctum::actingAs(userWithRole('accountant'));
    $provider = Provider::factory()->create();
    $camera = Article::factory()->create(['designation' => 'Caméra bullet', 'unit_cost' => 40000, 'provider_id' => $provider->id]);

    $purchase = $this->postJson('/api/v1/purchases', [
        'provider_id' => $provider->id, 'title' => 'Réassort caméras', 'tax_mode' => 'vat',
        'lines' => [['article_id' => $camera->id, 'quantity' => 10], ['designation' => 'Transport', 'quantity' => 1, 'unit_cost' => 15000]],
    ])->assertCreated()->json('data');

    expect($purchase['number'])->toBe('BC-'.now()->year.'-0001')
        ->and($purchase['status']['label'])->toBe('Brouillon')
        ->and($purchase['lines'][0])->toMatchArray(['designation' => 'Caméra bullet', 'unit_cost' => 40000, 'line_total' => 400000])
        ->and($purchase['subtotal'])->toBe(415000)
        ->and($purchase['total'])->toBe(489700);
    expect(substr($this->get("/api/v1/purchases/{$purchase['id']}/pdf")->assertOk()->getContent(), 0, 4))->toBe('%PDF');
});

it('commande le stock bas d\'un fournisseur pour revenir à deux fois le seuil', function () {
    Sanctum::actingAs(userWithRole('accountant'));
    $provider = Provider::factory()->create();
    Article::factory()->inStock(2, 5)->create(['provider_id' => $provider->id, 'designation' => 'A — détecteur']);
    Article::factory()->inStock(20, 5)->create(['provider_id' => $provider->id]);
    Article::factory()->inStock(0, 3)->create(['designation' => 'Autre fournisseur']);

    $lines = $this->postJson('/api/v1/purchases', ['provider_id' => $provider->id, 'low_stock' => true])->json('data.lines');

    expect($lines)->toHaveCount(1)->and($lines[0])->toMatchArray(['designation' => 'A — détecteur', 'quantity' => 8]);
});

it('réceptionne en plusieurs fois : le stock augmente et le coût suit le prix d\'achat', function () {
    Sanctum::actingAs(userWithRole('accountant'));
    $article = Article::factory()->inStock(1)->create(['unit_cost' => 30000]);
    $purchase = $this->postJson('/api/v1/purchases', [
        'provider_id' => Provider::factory()->create()->id,
        'lines' => [['article_id' => $article->id, 'quantity' => 10, 'unit_cost' => 32000], ['designation' => 'Fixations', 'quantity' => 4]],
    ])->json('data');
    [$cameraLine, $freeLine] = $purchase['lines'];

    Sanctum::actingAs(userWithRole('storekeeper'));
    $this->postJson("/api/v1/purchases/{$purchase['id']}/receive")->assertStatus(422)
        ->assertJsonPath('message', 'Commandez cet achat avant de le réceptionner.');
    $this->postJson("/api/v1/purchases/{$purchase['id']}/order")->assertForbidden();

    Sanctum::actingAs(userWithRole('accountant'));
    $this->postJson("/api/v1/purchases/{$purchase['id']}/order", ['expected_at' => now()->addWeek()->toDateString()])
        ->assertJsonPath('data.status.label', 'Commandé');
    $this->postJson("/api/v1/purchase-lines/{$cameraLine['id']}", [])->assertStatus(405);
    $this->patchJson("/api/v1/purchase-lines/{$cameraLine['id']}", ['quantity' => 3])->assertStatus(422);

    Sanctum::actingAs(userWithRole('storekeeper'));
    $this->postJson("/api/v1/purchases/{$purchase['id']}/receive", ['lines' => [['id' => $cameraLine['id'], 'quantity' => 12]]])
        ->assertStatus(422)->assertJsonPath('message', "Plus que commandé : {$article->designation} (reste 10).");
    $partial = $this->postJson("/api/v1/purchases/{$purchase['id']}/receive", ['lines' => [['id' => $cameraLine['id'], 'quantity' => 6]], 'note' => 'Carton abîmé'])
        ->assertOk()->json('data');
    expect($partial['status']['label'])->toBe('Reçu en partie')
        ->and($partial['lines'][0]['remaining_quantity'])->toEqual(4)
        ->and($article->refresh()->quantity)->toEqual(7)
        ->and($article->unit_cost)->toEqual(32000)
        ->and($article->movements()->first()->type->label())->toBe("Réception d'achat");

    // Le reste en une fois
    $done = $this->postJson("/api/v1/purchases/{$purchase['id']}/receive")->assertOk()->json('data');
    expect($done['status']['value'])->toBe('received')->and($done['receptions'])->toHaveCount(2)
        ->and($article->refresh()->quantity)->toEqual(11)
        ->and(collect($done['lines'])->firstWhere('id', $freeLine['id'])['received_quantity'])->toEqual(4);
    $this->postJson("/api/v1/purchases/{$purchase['id']}/cancel")->assertForbidden();
});

it('laisse le chef de projet préparer un brouillon sans prix ; le comptable le chiffre', function () {
    $pm = userWithRole('project_manager');
    Sanctum::actingAs($pm);
    $provider = Provider::factory()->create();
    $this->postJson('/api/v1/purchases', ['provider_id' => $provider->id, 'lines' => [['designation' => 'Câble', 'quantity' => 3, 'unit_cost' => 1]]])
        ->assertForbidden();
    $draft = $this->postJson('/api/v1/purchases', ['provider_id' => $provider->id, 'lines' => [['designation' => 'Câble 4 paires', 'quantity' => 3]]])
        ->assertCreated()->json('data');
    expect($draft)->not->toHaveKey('total')->and($draft['lines'][0])->not->toHaveKey('unit_cost')
        ->and($draft['can'])->toMatchArray(['edit' => true, 'order' => false, 'delete' => true]);

    // Il ne voit que ses achats
    Purchase::forceCreate(['number' => 'BC-X', 'provider_id' => $provider->id]);
    $this->getJson('/api/v1/purchases')->assertJsonCount(1, 'data');

    Sanctum::actingAs(userWithRole('accountant'));
    $this->patchJson("/api/v1/purchase-lines/{$draft['lines'][0]['id']}", ['unit_cost' => 50000])->assertJsonPath('data.total', 150000);
    $this->postJson("/api/v1/purchases/{$draft['id']}/order")->assertOk();

    Sanctum::actingAs($pm);
    $this->patchJson("/api/v1/purchases/{$draft['id']}", ['title' => 'X'])->assertForbidden();
    Sanctum::actingAs(userWithRole('technician'));
    $this->getJson('/api/v1/purchases')->assertForbidden();
});
