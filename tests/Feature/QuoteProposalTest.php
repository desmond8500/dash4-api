<?php

use App\Models\Article;
use App\Models\Client;
use App\Models\Quote;
use App\Support\QuotePdf;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

it('ne reprend dans la proposition technique que les centrales et les organes, par catégorie (comme Dash3)', function () {
    Sanctum::actingAs(userWithRole('sales'));
    $client = Client::factory()->create();
    $quote = $this->postJson('/api/v1/quotes', ['client_id' => $client->id, 'title' => 'Incendie'])->json('data');
    $section = $quote['sections'][0]['id'];
    foreach ([
        ['Détecteur', 'device'], ['Câble', 'cable'], ['Centrale', 'central'], ['Pose', 'service'],
        ['Déclencheur', 'device'], ['Chevilles', 'accessory'], ['Divers', 'other'],
    ] as [$designation, $category]) {
        $this->postJson("/api/v1/quote-sections/{$section}/lines", ['designation' => $designation, 'category' => $category, 'unit_cost' => 100])->assertOk();
    }

    $lines = QuotePdf::proposalLines(Quote::find($quote['id'])->sections()->first()->lines);

    expect($lines->pluck('designation')->all())->toBe(['Centrale', 'Détecteur', 'Déclencheur']);
});

it('produit la proposition technique avec la photo, la fiche technique et la description des articles', function () {
    Storage::fake('public');
    Sanctum::actingAs(userWithRole('sales'));
    $article = Article::factory()->create([
        'designation' => 'Caméra dôme 4 MP',
        'description' => 'Vision de nuit **30 m**',
        'category' => 'device',
        'image_path' => UploadedFile::fake()->image('dome.png', 200, 150)->store('articles/images', 'public'),
    ]);
    $article->links()->create(['label' => 'Fiche Technique', 'url' => 'https://example.com/dome.pdf']);

    $client = Client::factory()->create();
    $quote = $this->postJson('/api/v1/quotes', ['client_id' => $client->id, 'title' => 'Vidéo'])->json('data');
    $first = $quote['sections'][0]['id'];
    $this->patchJson("/api/v1/quote-sections/{$first}", ['proposal' => 'Caméras extérieures.'])->assertOk();
    $this->postJson("/api/v1/quote-sections/{$first}/lines", ['article_id' => $article->id, 'quantity' => 6, 'comment' => 'Façade nord'])->assertOk();
    // Une section sans équipement ni texte n'apparaît pas
    $this->postJson("/api/v1/quotes/{$quote['id']}/sections", ['title' => 'Main-d\'œuvre']);

    $response = $this->get("/api/v1/quotes/{$quote['id']}/proposal-pdf")->assertOk();
    expect(substr($response->getContent(), 0, 4))->toBe('%PDF');

    $html = view('pdf.proposal', [
        'company' => \App\Models\Company::current(), 'currency' => 'XOF', 'logo' => null,
        'quote' => $model = Quote::find($quote['id']),
        'sections' => $model->sections()->with('lines.article.links')->get()
            ->each(fn ($s) => $s->setRelation('lines', QuotePdf::proposalLines($s->lines)))
            ->filter(fn ($s) => $s->proposal || $s->lines->isNotEmpty())->values(),
        'photos' => [$article->id => 'data:image/png;base64,AA=='],
        'options' => $model->proposalOptions(),
    ])->render();

    expect($html)->toContain('Détails techniques')
        ->toContain('Caméras extérieures.')
        ->toContain('Caméra dôme 4 MP')
        ->toContain('<strong>30 m</strong>')
        ->toContain('https://example.com/dome.pdf')
        ->toContain('Façade nord')
        ->toContain('data:image/png;base64,AA==')
        ->not->toContain("Main-d'œuvre")->not->toContain('Main-d&#039;œuvre');
});
