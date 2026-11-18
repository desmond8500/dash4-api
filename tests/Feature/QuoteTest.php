<?php

use App\Models\BankAccount;
use App\Models\Client;
use App\Models\ClientRequest;
use App\Models\Project;
use App\Models\Quote;
use App\Support\Settings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/** Crée un devis par l'API et renvoie sa réponse JSON. */
function newQuote(array $data = []): array
{
    $client = Client::factory()->create(['name' => 'Hôtel Teranga']);

    return test()->postJson('/api/v1/quotes', ['client_id' => $client->id, 'title' => 'Vidéosurveillance', ...$data])
        ->assertCreated()->json('data');
}

function addLine(int $sectionId, array $data): array
{
    return test()->postJson("/api/v1/quote-sections/{$sectionId}/lines", $data)->assertOk()->json('data');
}

it('crée un devis numéroté, avec la taxe et le compte bancaire par défaut et une première section', function () {
    $bank = BankAccount::create(['bank_name' => 'CBAO', 'holder' => 'Yonkou', 'account_number' => '1']);
    Sanctum::actingAs(userWithRole('sales'));

    $quote = newQuote();

    expect($quote['number'])->toBe('DEV-'.now()->year.'-0001')
        ->and($quote['status']['label'])->toBe('Brouillon')
        ->and($quote['tax_mode']['value'])->toBe('vat')
        ->and($quote['vat_rate'])->toEqual(18)
        ->and($quote['bank_account_id'])->toBe($bank->id)
        ->and($quote['valid_until'])->toBe(now()->addDays(30)->toDateString())
        ->and($quote['sections'])->toHaveCount(1);

    expect(newQuote()['number'])->toBe('DEV-'.now()->year.'-0002');
});

it('calcule les lignes au coût × coefficient et les totaux avec la TVA', function () {
    Sanctum::actingAs(userWithRole('sales'));
    $quote = newQuote();
    $section = $quote['sections'][0]['id'];

    addLine($section, ['designation' => 'Caméra IP 4 MP', 'quantity' => 4, 'unit_cost' => 50000, 'coefficient' => 1.5, 'category' => 'device']);
    $quote = addLine($section, ['designation' => 'Pose', 'quantity' => 1, 'unit_cost' => 100000, 'coefficient' => 1, 'category' => 'service']);

    $line = $quote['sections'][0]['lines'][0];
    expect($line['unit_price'])->toBe(75000)
        ->and($line['line_total'])->toBe(300000)
        ->and($line['category']['label'])->toBe('Organe')
        ->and($quote['subtotal'])->toBe(400000)
        ->and($quote['tax_amount'])->toBe(72000)
        ->and($quote['total'])->toBe(472000)
        ->and($quote['cost_total'])->toEqual(300000)
        ->and($quote['margin'])->toBe(100000)
        ->and($quote['margin_rate'])->toEqual(25);
});

it('applique la remise avant la taxe, et déduit la BRS', function () {
    Sanctum::actingAs(userWithRole('sales'));
    $quote = newQuote();
    addLine($quote['sections'][0]['id'], ['designation' => 'Forfait', 'unit_cost' => 1000000]);

    $this->patchJson("/api/v1/quotes/{$quote['id']}", ['discount_rate' => 10, 'tax_mode' => 'brs'])
        ->assertOk()
        ->assertJsonPath('data.discount_amount', 100000)
        ->assertJsonPath('data.net_amount', 900000)
        ->assertJsonPath('data.tax_amount', 45000)
        ->assertJsonPath('data.total', 855000);
});

it('ne compte pas une section masquée', function () {
    Sanctum::actingAs(userWithRole('sales'));
    $quote = newQuote();
    addLine($quote['sections'][0]['id'], ['designation' => 'A', 'unit_cost' => 1000]);
    $second = $this->postJson("/api/v1/quotes/{$quote['id']}/sections", ['title' => 'Option'])->json('data.sections.1.id');
    addLine($second, ['designation' => 'B', 'unit_cost' => 5000]);

    $this->patchJson("/api/v1/quote-sections/{$second}", ['is_visible' => false])
        ->assertJsonPath('data.subtotal', 1000);
});

it('déplace et réordonne les lignes et les sections', function () {
    Sanctum::actingAs(userWithRole('sales'));
    $quote = newQuote();
    $first = $quote['sections'][0]['id'];
    addLine($first, ['designation' => 'A']);
    $quote = addLine($first, ['designation' => 'B']);
    [$a, $b] = array_column($quote['sections'][0]['lines'], 'id');

    $this->postJson("/api/v1/quote-sections/{$first}/lines/order", ['ids' => [$b, $a]])
        ->assertJsonPath('data.sections.0.lines.0.designation', 'B');

    $second = $this->postJson("/api/v1/quotes/{$quote['id']}/sections", ['title' => 'Câblage'])->json('data.sections.1.id');
    $this->patchJson("/api/v1/quote-lines/{$a}", ['quote_section_id' => $second])
        ->assertJsonPath('data.sections.1.lines.0.designation', 'A');

    $this->postJson("/api/v1/quotes/{$quote['id']}/sections/order", ['ids' => [$second, $first]])
        ->assertJsonPath('data.sections.0.title', 'Câblage');

    // Pas de ligne vers la section d'un autre devis
    $other = newQuote()['sections'][0]['id'];
    $this->patchJson("/api/v1/quote-lines/{$a}", ['quote_section_id' => $other])->assertJsonValidationErrors('quote_section_id');
});

it('cache les coûts au chef de projet, qui rédige les lignes sans les chiffrer', function () {
    $chef = userWithRole('project_manager');
    $client = Client::factory()->create();
    $project = Project::factory()->create(['client_id' => $client->id, 'manager_id' => $chef->id]);
    Sanctum::actingAs($chef);

    $quote = $this->postJson('/api/v1/quotes', ['client_id' => $client->id, 'project_id' => $project->id, 'title' => 'Alarme'])
        ->assertCreated()->json('data');
    $section = $quote['sections'][0]['id'];

    $this->postJson("/api/v1/quote-sections/{$section}/lines", ['designation' => 'Détecteur', 'unit_cost' => 10000])
        ->assertForbidden();
    $quote = addLine($section, ['designation' => 'Détecteur', 'quantity' => 6]);

    expect($quote)->toHaveKey('total')->not->toHaveKey('cost_total')->not->toHaveKey('margin')
        ->and($quote['sections'][0]['lines'][0])->toHaveKey('unit_price')->not->toHaveKey('unit_cost')
        ->and($quote['can']['edit_prices'])->toBeFalse();

    // Il ne voit pas les devis des autres
    Sanctum::actingAs(userWithRole('sales'));
    $other = newQuote();
    Sanctum::actingAs($chef);
    $this->getJson("/api/v1/quotes/{$other['id']}")->assertForbidden();
    $this->getJson('/api/v1/quotes')->assertJsonCount(1, 'data');
});

it('ferme les devis au technicien et au magasinier ne voyant pas les prix', function () {
    Sanctum::actingAs(userWithRole('sales'));
    $quote = newQuote();

    Sanctum::actingAs(userWithRole('technician'));
    $this->getJson('/api/v1/quotes')->assertForbidden();
    $this->getJson("/api/v1/quotes/{$quote['id']}/pdf")->assertForbidden();
});

it('vérifie que le projet appartient au client', function () {
    Sanctum::actingAs(userWithRole('sales'));
    $project = Project::factory()->create(['client_id' => Client::factory()->create()->id]);

    $this->postJson('/api/v1/quotes', ['client_id' => Client::factory()->create()->id, 'project_id' => $project->id, 'title' => 'X'])
        ->assertJsonPath('errors.project_id.0', "Ce projet n'appartient pas au client du devis.");
});

it('suit les statuts : envoyé, puis accepté seulement avec quotes.validate', function () {
    $sales = userWithRole('sales');
    Sanctum::actingAs($sales);
    $quote = newQuote();

    $this->postJson("/api/v1/quotes/{$quote['id']}/status", ['status' => 'refused'])
        ->assertStatus(422)->assertJsonPath('message', 'Un devis « Brouillon » ne peut pas passer à « Refusé ».');
    $this->postJson("/api/v1/quotes/{$quote['id']}/status", ['status' => 'sent'])->assertJsonPath('data.status.label', 'Envoyé');

    // Le comptable peut voir mais pas décider
    Sanctum::actingAs(userWithRole('accountant'));
    $this->postJson("/api/v1/quotes/{$quote['id']}/status", ['status' => 'accepted'])->assertForbidden();

    Sanctum::actingAs($sales);
    $this->postJson("/api/v1/quotes/{$quote['id']}/status", ['status' => 'accepted'])
        ->assertOk()->assertJsonPath('data.status.label', 'Accepté');
    expect(Quote::find($quote['id'])->decided_by)->toBe($sales->id);
});

it('bloque la modification d\'un devis accepté, qui se duplique', function () {
    Sanctum::actingAs(userWithRole('sales'));
    $quote = newQuote();
    $section = $quote['sections'][0]['id'];
    addLine($section, ['designation' => 'Caméra', 'unit_cost' => 50000, 'coefficient' => 1.4, 'quantity' => 2]);
    $this->postJson("/api/v1/quotes/{$quote['id']}/status", ['status' => 'accepted'])->assertOk();

    $this->postJson("/api/v1/quote-sections/{$section}/lines", ['designation' => 'X'])
        ->assertStatus(422)->assertJsonPath('message', 'Ce devis est « Accepté » : dupliquez-le pour le modifier.');
    $this->patchJson("/api/v1/quotes/{$quote['id']}", ['is_favorite' => true])->assertOk();

    $copy = $this->postJson("/api/v1/quotes/{$quote['id']}/duplicate")->assertCreated()->json('data');
    expect($copy['number'])->not->toBe($quote['number'])
        ->and($copy['status']['value'])->toBe('draft')
        ->and($copy['total'])->toBe(165200)
        ->and($copy['sections'][0]['lines'][0]['designation'])->toBe('Caméra')
        ->and($copy['is_favorite'])->toBeFalse();
});

it('garde les taux du devis même si les paramètres changent ensuite', function () {
    Sanctum::actingAs(userWithRole('sales'));
    $quote = newQuote();
    Settings::set(['vat_rate' => 20]);
    addLine($quote['sections'][0]['id'], ['designation' => 'A', 'unit_cost' => 100000]);

    $this->getJson("/api/v1/quotes/{$quote['id']}")->assertJsonPath('data.tax_amount', 18000);
    expect(newQuote()['vat_rate'])->toEqual(20);
});

it('produit le PDF du devis', function () {
    Sanctum::actingAs(userWithRole('sales'));
    $quote = newQuote(['introduction' => 'Suite à votre demande', 'payment_terms' => '50 % à la commande']);
    addLine($quote['sections'][0]['id'], ['designation' => 'Caméra', 'unit_cost' => 50000, 'coefficient' => 1.5, 'quantity' => 2]);

    $response = $this->get("/api/v1/quotes/{$quote['id']}/pdf")->assertOk();
    expect($response->headers->get('content-type'))->toBe('application/pdf')
        ->and(substr($response->getContent(), 0, 4))->toBe('%PDF');
});

it('enregistre la copie signée par le client et accepte le devis', function () {
    Storage::fake('local');
    Sanctum::actingAs(userWithRole('sales'));
    $quote = newQuote();
    $this->postJson("/api/v1/quotes/{$quote['id']}/status", ['status' => 'sent']);

    $this->post("/api/v1/quotes/{$quote['id']}/signed-copy", [
        'file' => UploadedFile::fake()->create('scan.pdf', 200, 'application/pdf'),
    ], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('data.status.value', 'accepted')
        ->assertJsonPath('data.signed_document_id', fn ($id) => $id !== null);

    $this->getJson("/api/v1/quotes/{$quote['id']}/documents")
        ->assertJsonPath('data.0.name', 'Copie signée '.$quote['number'].'.pdf');
});

it('filtre les devis par statut, client et recherche', function () {
    Sanctum::actingAs(userWithRole('sales'));
    $a = newQuote(['title' => 'Alarme entrepôt']);
    newQuote(['title' => 'Caméras']);
    $this->postJson("/api/v1/quotes/{$a['id']}/status", ['status' => 'sent']);

    $this->getJson('/api/v1/quotes?status=sent')->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/quotes?search=entrep')->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/quotes?search=teranga')->assertJsonCount(2, 'data');
    $this->getJson("/api/v1/quotes?client_id={$a['client_id']}")->assertJsonCount(1, 'data');
});

it('réserve la suppression aux administrateurs et managers', function () {
    Sanctum::actingAs(userWithRole('sales'));
    $quote = newQuote();
    $this->deleteJson("/api/v1/quotes/{$quote['id']}")->assertForbidden();

    Sanctum::actingAs(userWithRole('manager'));
    $this->deleteJson("/api/v1/quotes/{$quote['id']}")->assertOk();
});

it('transforme une demande client en devis', function () {
    Sanctum::actingAs(userWithRole('sales'));
    $client = Client::factory()->create();
    $request = ClientRequest::factory()->create(['body' => 'Devis pour 8 caméras au dépôt', 'client_id' => $client->id]);

    $this->postJson("/api/v1/client-requests/{$request->id}/convert", ['type' => 'quote'])
        ->assertCreated()
        ->assertJsonPath('data.request.converted_to.type', 'quote')
        ->assertJsonPath('data.quote.title', 'Devis pour 8 caméras au dépôt')
        ->assertJsonPath('data.quote.client_id', $client->id)
        ->assertJsonPath('data.quote.status.value', 'draft');
});
