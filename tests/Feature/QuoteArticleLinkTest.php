<?php

use App\Models\Article;
use App\Models\Quote;
use Laravel\Sanctum\Sanctum;

it('lie une ligne de devis à un article du stock sans changer la ligne, même sur un devis accepté', function () {
    Sanctum::actingAs(userWithRole('sales'));
    $article = Article::factory()->create(['designation' => 'Caméra dôme 4 MP', 'reference' => 'DS-2CD1143', 'unit_cost' => 40000]);
    $quote = newQuote();
    $data = addLine($quote['sections'][0]['id'], ['designation' => 'Dôme couloir', 'quantity' => 2, 'unit_cost' => 50000, 'coefficient' => 1.2]);
    $lineId = $data['sections'][0]['lines'][0]['id'];
    Quote::find($quote['id'])->forceFill(['status' => 'accepted'])->save();

    $line = $this->patchJson("/api/v1/quote-lines/{$lineId}/article", ['article_id' => $article->id])->assertOk()->json('data.sections.0.lines.0');
    expect($line)->toMatchArray(['article_id' => $article->id, 'designation' => 'Dôme couloir', 'unit_cost' => 50000])
        ->and($line['article'])->toMatchArray(['id' => $article->id, 'reference' => 'DS-2CD1143']);

    $this->patchJson("/api/v1/quote-lines/{$lineId}/article", ['article_id' => null])->assertJsonPath('data.sections.0.lines.0.article', null);
});

it('lie automatiquement les lignes à l\'article de même référence ou de même désignation, sans deviner', function () {
    Sanctum::actingAs(userWithRole('sales'));
    $dome = Article::factory()->create(['designation' => 'Caméra dôme', 'reference' => 'DS-2CD1143']);
    $cable = Article::factory()->create(['designation' => 'Câble UTP cat6', 'reference' => null]);
    Article::factory()->create(['designation' => 'Coffret', 'reference' => 'X1']);
    Article::factory()->create(['designation' => 'Coffret', 'reference' => 'X2']);
    $quote = newQuote();
    $sectionId = $quote['sections'][0]['id'];
    addLine($sectionId, ['designation' => 'Dôme', 'reference' => ' ds-2cd1143 ']);
    addLine($sectionId, ['designation' => 'câble utp  cat6']);
    addLine($sectionId, ['designation' => 'Coffret']);

    $r = $this->postJson("/api/v1/quotes/{$quote['id']}/link-articles")->assertOk();
    expect($r->json('linked'))->toBe(2)
        ->and(collect($r->json('data.sections.0.lines'))->pluck('article_id')->all())->toBe([$dome->id, $cable->id, null]);
});
