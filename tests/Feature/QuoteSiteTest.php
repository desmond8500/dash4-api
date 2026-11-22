<?php

use App\Models\Article;
use App\Models\Project;
use App\Models\Quote;
use Laravel\Sanctum\Sanctum;

/** Devis d'un projet avec une section « Vidéosurveillance » : 1 NVR, 3 caméras, 50 m de câble, la pose. */
function quoteForSite(Project $project): array
{
    $quote = Quote::factory()->create(['project_id' => $project->id]);
    $article = Article::factory()->create(['designation' => 'Caméra dôme 4 MP', 'reference' => 'DS-2CD']);
    $section = $quote->sections()->create(['position' => 0, 'title' => 'Vidéosurveillance']);
    $nvr = $section->lines()->create(['position' => 0, 'designation' => 'Enregistreur 16 voies', 'reference' => 'NVR16', 'category' => 'central', 'quantity' => 1, 'unit_cost' => 150000]);
    $cam = $section->lines()->create(['position' => 1, 'article_id' => $article->id, 'designation' => 'Caméra dôme 4 MP', 'category' => 'device', 'quantity' => 3, 'unit_cost' => 50000, 'comment' => 'Façade']);
    $cable = $section->lines()->create(['position' => 2, 'designation' => 'Câble RJ45', 'category' => 'cable', 'quantity' => 50, 'unit' => 'm']);

    return [$quote, $nvr, $cam, $cable];
}

it('crée les appareils du chantier depuis les lignes du devis, sans doublon', function () {
    $pm = userWithRole('project_manager');
    $project = Project::factory()->create(['manager_id' => $pm->id]);
    [$quote, $nvr, $cam] = quoteForSite($project);
    Sanctum::actingAs($pm);

    // Nouveau système nommé comme la section ; un appareil par unité pour les caméras
    $response = $this->postJson("/api/v1/quotes/{$quote->id}/site-equipment", [
        'split' => true,
        'groups' => [['system_name' => 'Vidéosurveillance', 'line_ids' => [$nvr->id, $cam->id]]],
    ])->assertCreated();
    expect($response->json('meta.created'))->toBe(4);
    $system = $response->json('data.systems.0');
    expect($system['name'])->toBe('Vidéosurveillance')
        ->and(collect($system['equipment'])->pluck('name')->all())->toBe(['Enregistreur 16 voies', 'Caméra dôme 4 MP', 'Caméra dôme 4 MP', 'Caméra dôme 4 MP']);
    $camera = $system['equipment'][1];
    expect($camera)->toMatchArray(['quote_line_id' => $cam->id, 'quantity' => 1, 'type' => 'Organe', 'model' => 'DS-2CD', 'description' => 'Façade'])
        ->and($camera['status']['value'])->toBe('planned')
        ->and($camera['article'])->not->toHaveKey('unit_cost');

    // Relancer (ou rejouer depuis le mobile) : rien de plus, même vers le même nom de système
    $again = $this->postJson("/api/v1/quotes/{$quote->id}/site-equipment", [
        'groups' => [['system_name' => 'vidéosurveillance', 'line_ids' => [$nvr->id, $cam->id]]],
    ])->assertOk();
    expect($again->json('meta.created'))->toBe(0)->and($again->json('data.systems'))->toHaveCount(1);

    // Une caméra de plus au devis : seule la nouvelle est créée, dans le système existant
    $cam->update(['quantity' => 4]);
    $more = $this->postJson("/api/v1/quotes/{$quote->id}/site-equipment", [
        'groups' => [['installed_system_id' => $system['id'], 'line_ids' => [$cam->id]]],
    ])->assertCreated();
    expect($more->json('meta.created'))->toBe(1)->and($more->json('data.equipment_count'))->toBe(5);
});

it('regroupe la quantité en un seul appareil quand on ne découpe pas', function () {
    $pm = userWithRole('project_manager');
    $project = Project::factory()->create(['manager_id' => $pm->id]);
    [$quote, , $cam] = quoteForSite($project);
    Sanctum::actingAs($pm);

    $site = $this->postJson("/api/v1/quotes/{$quote->id}/site-equipment", ['groups' => [['system_name' => 'Vidéo', 'line_ids' => [$cam->id]]]])->json('data');
    expect($site['systems'][0]['equipment'])->toHaveCount(1)->and($site['systems'][0]['equipment'][0]['quantity'])->toBe(3);
});

it('refuse les lignes d’un autre devis, un devis sans projet et un compte sans droit sur le chantier', function () {
    $pm = userWithRole('project_manager');
    $project = Project::factory()->create(['manager_id' => $pm->id]);
    [$quote, $nvr] = quoteForSite($project);
    [, $otherLine] = quoteForSite($project);
    Sanctum::actingAs($pm);

    $this->postJson("/api/v1/quotes/{$quote->id}/site-equipment", ['groups' => [['system_name' => 'X', 'line_ids' => [$otherLine->id]]]])
        ->assertStatus(422)->assertJsonPath('message', "Cette ligne n'est pas dans le devis.");
    $this->postJson("/api/v1/quotes/{$quote->id}/site-equipment", ['groups' => [['line_ids' => [$nvr->id]]]])->assertStatus(422);

    $loose = Quote::factory()->create(['project_id' => null, 'created_by' => $pm->id]);
    $this->postJson("/api/v1/quotes/{$loose->id}/site-equipment", ['groups' => [['system_name' => 'X', 'line_ids' => [1]]]])
        ->assertStatus(422)->assertJsonPath('message', "Rattachez d'abord le devis à un projet.");

    Sanctum::actingAs(userWithRole('technician'));
    $this->postJson("/api/v1/quotes/{$quote->id}/site-equipment", ['groups' => [['system_name' => 'X', 'line_ids' => [$nvr->id]]]])->assertForbidden();
});
