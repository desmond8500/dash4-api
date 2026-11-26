<?php

use App\Models\Project;
use App\Notifications\SiteAssignedNotification;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

it('affecte un technicien à un projet : il en voit le chantier, et rien d\'autre du projet', function () {
    Notification::fake();
    $manager = userWithRole('manager');
    $tech = userWithRole('technician');
    $project = Project::factory()->create(['name' => 'Hôtel Teranga', 'address' => 'Dakar Plateau']);

    // Avant l'affectation : rien
    Sanctum::actingAs($tech);
    $this->getJson("/api/v1/projects/{$project->id}/site")->assertForbidden();
    $this->getJson('/api/v1/site-projects')->assertOk()->assertJsonCount(0, 'data');

    Sanctum::actingAs($manager);
    $this->putJson("/api/v1/projects/{$project->id}/technicians", ['user_ids' => [$tech->id]])->assertOk()
        ->assertJsonPath('data.0.id', $tech->id);
    Notification::assertSentTo($tech, SiteAssignedNotification::class, fn ($n) => $n->toArray($tech)['project_id'] === $project->id);

    Sanctum::actingAs($tech);
    $this->getJson("/api/v1/projects/{$project->id}/site")->assertOk();
    $this->getJson('/api/v1/site-projects')->assertOk()
        ->assertJsonPath('data.0.id', $project->id)
        ->assertJsonPath('data.0.name', 'Hôtel Teranga')
        ->assertJsonPath('data.0.assigned', true)
        ->assertJsonMissingPath('data.0.manager_id');
    // La fiche du projet, ses devis et ses notes restent fermés
    $this->getJson("/api/v1/projects/{$project->id}")->assertForbidden();
    $this->getJson("/api/v1/projects/{$project->id}/technicians")->assertForbidden();
    $this->getJson("/api/v1/projects/{$project->id}/notes")->assertForbidden();
    $this->getJson('/api/v1/projects')->assertOk()->assertJsonCount(0, 'data');
});

it('retire l\'accès quand le technicien n\'est plus affecté, et ne prévient que les nouveaux', function () {
    Notification::fake();
    $manager = userWithRole('manager');
    [$a, $b] = [userWithRole('technician'), userWithRole('technician')];
    $project = Project::factory()->create();

    Sanctum::actingAs($manager);
    $this->putJson("/api/v1/projects/{$project->id}/technicians", ['user_ids' => [$a->id]])->assertOk();
    $this->putJson("/api/v1/projects/{$project->id}/technicians", ['user_ids' => [$a->id, $b->id]])->assertOk()->assertJsonCount(2, 'data');
    Notification::assertSentToTimes($a, SiteAssignedNotification::class, 1);
    Notification::assertSentToTimes($b, SiteAssignedNotification::class, 1);

    $this->putJson("/api/v1/projects/{$project->id}/technicians", ['user_ids' => [$b->id]])->assertOk()->assertJsonCount(1, 'data');
    Sanctum::actingAs($a);
    $this->getJson("/api/v1/projects/{$project->id}/site")->assertForbidden();
});

it('n\'affecte que des comptes technicien actifs, par qui peut modifier le projet', function () {
    $manager = userWithRole('manager');
    $project = Project::factory()->create();

    Sanctum::actingAs($manager);
    $this->putJson("/api/v1/projects/{$project->id}/technicians", ['user_ids' => [userWithRole('sales')->id]])->assertStatus(422);
    $this->putJson("/api/v1/projects/{$project->id}/technicians", ['user_ids' => [userWithRole('technician', ['is_active' => false])->id]])->assertStatus(422);

    // Chef de projet : seulement sur ses projets
    $chef = userWithRole('project_manager');
    Sanctum::actingAs($chef);
    $this->putJson("/api/v1/projects/{$project->id}/technicians", ['user_ids' => [userWithRole('technician')->id]])->assertForbidden();
    $mine = Project::factory()->create(['manager_id' => $chef->id]);
    $this->putJson("/api/v1/projects/{$mine->id}/technicians", ['user_ids' => [userWithRole('technician')->id]])->assertOk();
});
