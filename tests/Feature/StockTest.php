<?php

use App\Models\Article;
use App\Models\Client;
use App\Models\Project;
use App\Notifications\LowStockNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

it('crée un article avec marque, fournisseur et stock de départ', function () {
    Sanctum::actingAs(userWithRole('storekeeper'));
    $brand = $this->postJson('/api/v1/brands', ['name' => 'Hikvision'])->assertCreated()->json('data');
    $provider = $this->postJson('/api/v1/providers', ['name' => 'Sénégal Sécurité', 'phone' => '+221 33 800 00 00'])->assertCreated()->json('data');
    $this->postJson('/api/v1/brands', ['name' => 'Hikvision'])->assertJsonPath('errors.name.0', 'Cette marque existe déjà.');

    $article = $this->postJson('/api/v1/articles', [
        'designation' => 'Caméra dôme 4 MP', 'reference' => 'DS-2CD1143', 'barcode' => '6941264012345', 'category' => 'device',
        'unit' => 'u', 'brand_id' => $brand['id'], 'provider_id' => $provider['id'],
        'unit_cost' => 35000, 'coefficient' => 1.6, 'min_quantity' => 5, 'initial_quantity' => 12,
    ])->assertCreated()->json('data');

    expect($article)->toMatchArray(['quantity' => 12, 'is_low' => false, 'unit_cost' => 35000, 'coefficient' => 1.6, 'stock_value' => 420000])
        ->and($article['brand']['name'])->toBe('Hikvision')
        ->and($article['movements'][0]['reason'])->toBe('Stock de départ');
    $this->getJson('/api/v1/articles?barcode=6941264012345')->assertJsonCount(1, 'data');
    $this->deleteJson("/api/v1/brands/{$brand['id']}")->assertStatus(422);
});

it('ne montre jamais ni prix ni coût à un technicien, et le prix sans le coût à un chef de projet', function () {
    $article = Article::factory()->inStock(3)->create();

    Sanctum::actingAs(userWithRole('technician'));
    $data = $this->getJson("/api/v1/articles/{$article->id}")->assertOk()->json('data');
    expect($data)->not->toHaveKeys(['unit_price', 'unit_cost', 'coefficient', 'stock_value'])->and($data['quantity'])->toEqual(3);
    $this->postJson("/api/v1/articles/{$article->id}/movements", ['type' => 'out', 'quantity' => 1])->assertForbidden();

    Sanctum::actingAs(userWithRole('project_manager'));
    $data = $this->getJson("/api/v1/articles/{$article->id}")->json('data');
    expect($data['unit_price'])->toBe(75000)->and($data)->not->toHaveKey('unit_cost');
});

it('enregistre entrées, sorties et inventaires, et refuse une sortie au-delà du stock', function () {
    $user = userWithRole('storekeeper');
    Sanctum::actingAs($user);
    $article = Article::factory()->inStock(10)->create();
    $project = Project::factory()->create();

    $this->postJson("/api/v1/articles/{$article->id}/movements", ['type' => 'in', 'quantity' => 5, 'reason' => 'Retour chantier'])
        ->assertCreated()->assertJsonPath('data.quantity', 15);
    $this->postJson("/api/v1/articles/{$article->id}/movements", ['type' => 'out', 'quantity' => 4, 'project_id' => $project->id])
        ->assertJsonPath('data.quantity', 11)->assertJsonPath('data.movements.0.project.id', $project->id);
    $this->postJson("/api/v1/articles/{$article->id}/movements", ['type' => 'out', 'quantity' => 20])
        ->assertStatus(422)->assertJsonPath('message', "Stock insuffisant pour « {$article->designation} » : 11 en stock, 20 demandé(s).");
    $this->postJson("/api/v1/articles/{$article->id}/movements", ['type' => 'inventory', 'quantity' => 9])
        ->assertJsonPath('data.quantity', 9)->assertJsonPath('data.movements.0.quantity', -2)->assertJsonPath('data.movements.0.type.label', 'Inventaire');

    // La quantité ne se modifie pas directement
    $this->patchJson("/api/v1/articles/{$article->id}", ['quantity' => 100])->assertOk();
    expect($article->refresh()->quantity)->toEqual(9);

    // Envoi hors ligne rejoué : pas de doublon
    $uuid = (string) Str::uuid();
    $this->postJson("/api/v1/articles/{$article->id}/movements", ['type' => 'out', 'quantity' => 1, 'client_uuid' => $uuid])->assertCreated();
    $this->postJson("/api/v1/articles/{$article->id}/movements", ['type' => 'out', 'quantity' => 1, 'client_uuid' => $uuid])->assertOk();
    expect($article->refresh()->quantity)->toEqual(8);
});

it('prévient quand un article passe sous son seuil, une seule fois', function () {
    Notification::fake();
    $keeper = userWithRole('storekeeper');
    $admin = userWithRole('admin');
    Sanctum::actingAs($keeper);
    $article = Article::factory()->inStock(6, 5)->create();

    $this->postJson("/api/v1/articles/{$article->id}/movements", ['type' => 'out', 'quantity' => 2]);
    $this->postJson("/api/v1/articles/{$article->id}/movements", ['type' => 'out', 'quantity' => 1]);

    Notification::assertSentToTimes($keeper, LowStockNotification::class, 1);
    Notification::assertSentTo($admin, LowStockNotification::class);
    $this->getJson('/api/v1/articles?low=1')->assertJsonCount(1, 'data')->assertJsonPath('data.0.is_low', true);
    $this->getJson('/api/v1/articles/summary')->assertJsonPath('data.low', 1);
});

it('remplit une ligne de devis depuis un article du stock', function () {
    Sanctum::actingAs(userWithRole('sales'));
    $article = Article::factory()->create(['designation' => 'NVR 8 voies', 'category' => 'central', 'unit_cost' => 100000, 'coefficient' => 1.3]);
    $quote = $this->postJson('/api/v1/quotes', ['client_id' => Client::factory()->create()->id, 'title' => 'Vidéo'])->json('data');

    $line = $this->postJson("/api/v1/quote-sections/{$quote['sections'][0]['id']}/lines", ['article_id' => $article->id, 'quantity' => 2])
        ->assertOk()->json('data.sections.0.lines.0');

    expect($line)->toMatchArray(['article_id' => $article->id, 'designation' => 'NVR 8 voies', 'unit_cost' => 100000, 'coefficient' => 1.3, 'unit_price' => 130000, 'line_total' => 260000])
        ->and($line['category']['value'])->toBe('central');
});

it('sort le matériel d\'un bordereau livré du stock, et le remet en stock à l\'annulation', function () {
    Sanctum::actingAs(userWithRole('sales'));
    $nvr = Article::factory()->inStock(3)->create(['category' => 'central']);
    $cam = Article::factory()->inStock(1)->create(['category' => 'device']);
    $client = Client::factory()->create();
    $project = Project::factory()->create(['client_id' => $client->id]);
    $quote = $this->postJson('/api/v1/quotes', ['client_id' => $client->id, 'project_id' => $project->id, 'title' => 'Vidéo'])->json('data');
    $section = $quote['sections'][0]['id'];
    $this->postJson("/api/v1/quote-sections/{$section}/lines", ['article_id' => $nvr->id, 'quantity' => 1]);
    $this->postJson("/api/v1/quote-sections/{$section}/lines", ['article_id' => $cam->id, 'quantity' => 2]);
    $this->postJson("/api/v1/quote-sections/{$section}/lines", ['designation' => 'Câble libre', 'category' => 'cable', 'quantity' => 50]);

    $note = $this->postJson("/api/v1/quotes/{$quote['id']}/delivery-notes")->json('data.delivery_notes.0');
    expect(collect($note['lines'])->pluck('article_id')->all())->toBe([$nvr->id, $cam->id, null]);

    Sanctum::actingAs(userWithRole('storekeeper'));
    $this->postJson("/api/v1/delivery-notes/{$note['id']}/release")->assertStatus(422)
        ->assertJsonPath('message', "Stock insuffisant : {$cam->designation} (1 en stock, 2 à sortir).");
    expect($nvr->refresh()->quantity)->toEqual(3);

    $cam->forceFill(['quantity' => 5])->save();
    $this->postJson("/api/v1/delivery-notes/{$note['id']}/release")->assertOk();
    expect($nvr->refresh()->quantity)->toEqual(2)->and($cam->refresh()->quantity)->toEqual(3)
        ->and($cam->movements()->first()->project_id)->toBe($project->id);
    $this->postJson("/api/v1/delivery-notes/{$note['id']}/release")->assertStatus(422);

    Sanctum::actingAs(userWithRole('sales'));
    $this->deleteJson("/api/v1/delivery-notes/{$note['id']}")->assertStatus(422);
    $this->postJson("/api/v1/delivery-notes/{$note['id']}/restock")->assertOk();
    expect($nvr->refresh()->quantity)->toEqual(3)->and($cam->refresh()->quantity)->toEqual(5);
    $this->getJson("/api/v1/stock-movements?article_id={$cam->id}")->assertJsonCount(2, 'data')->assertJsonPath('data.0.source.number', $note['number']);
});

it('gère les articles liés', function () {
    Sanctum::actingAs(userWithRole('storekeeper'));
    $camera = Article::factory()->create();
    $bracket = Article::factory()->create(['designation' => 'Support mural']);

    $this->putJson("/api/v1/articles/{$camera->id}/dependencies", ['items' => [['article_id' => $bracket->id, 'quantity' => 1]]])
        ->assertJsonPath('data.dependencies.0.designation', 'Support mural');
    $this->putJson("/api/v1/articles/{$camera->id}/dependencies", ['items' => [['article_id' => $camera->id, 'quantity' => 1]]])
        ->assertJsonPath('errors', fn ($e) => str_contains(json_encode($e, JSON_UNESCAPED_UNICODE), 'lui-même'));
});
