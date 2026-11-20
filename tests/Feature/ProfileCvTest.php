<?php

use App\Models\Cv;
use App\Models\Project;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(fn () => Storage::fake('public'));

it('modifie son profil, sa photo et son mot de passe', function () {
    $tech = userWithRole('technician');
    Sanctum::actingAs($tech);

    $this->patchJson('/api/v1/me', ['firstname' => 'Awa', 'phone' => '77 000 00 00', 'job_title' => 'Électricienne'])
        ->assertOk()->assertJsonPath('data.firstname', 'Awa')->assertJsonPath('data.job_title', 'Électricienne');
    // L'e-mail et les rôles ne se changent pas ici
    $this->patchJson('/api/v1/me', ['email' => 'pirate@example.com'])->assertOk();
    expect($tech->refresh()->email)->not->toBe('pirate@example.com');

    $this->post('/api/v1/me/avatar', ['logo' => UploadedFile::fake()->image('moi.jpg')], ['Accept' => 'application/json'])
        ->assertOk()->assertJsonPath('data.avatar_url', fn ($u) => $u !== null);

    $this->putJson('/api/v1/me/password', ['current_password' => 'mauvais', 'password' => 'Nouveau2026x', 'password_confirmation' => 'Nouveau2026x'])
        ->assertStatus(422);
    $this->putJson('/api/v1/me/password', ['current_password' => 'password', 'password' => 'court', 'password_confirmation' => 'court'])
        ->assertJsonValidationErrors('password');
    $this->putJson('/api/v1/me/password', ['current_password' => 'password', 'password' => 'Nouveau2026x', 'password_confirmation' => 'Nouveau2026x'])
        ->assertOk();
    expect(Hash::check('Nouveau2026x', $tech->refresh()->password))->toBeTrue();
});

it('gère plusieurs CV, les remplit, les duplique et les imprime', function () {
    $pm = userWithRole('project_manager', ['job_title' => 'Chef de projet']);
    Project::factory()->create(['manager_id' => $pm->id, 'name' => 'Tour Diamniadio']);
    Sanctum::actingAs($pm);

    $cv = $this->postJson('/api/v1/me/cvs', ['name' => 'CV général'])->assertCreated()
        ->assertJsonPath('data.title', 'Chef de projet')->assertJsonCount(4, 'data.sections')->json('data');

    $this->patchJson("/api/v1/cvs/{$cv['id']}", [
        'summary' => 'Dix ans de chantiers.',
        'accent_color' => '#0f766e',
        'sections' => [
            ['name' => 'Expériences', 'type' => 'experience', 'items' => [
                ['title' => 'Chef de projet', 'subtitle' => 'Yonkou', 'start_date' => '2020', 'current' => true, 'description' => 'Courants faibles.'],
            ]],
            ['name' => 'Langues', 'type' => 'languages', 'items' => [['title' => 'Anglais', 'level' => 4]]],
        ],
    ])->assertOk()->assertJsonCount(2, 'data.sections')->assertJsonPath('data.sections.1.items.0.level', 4);
    $this->patchJson("/api/v1/cvs/{$cv['id']}", ['accent_color' => 'rouge'])->assertJsonValidationErrors('accent_color');

    // Les projets Dash4 du chef de projet, sans doublon
    $this->postJson("/api/v1/cvs/{$cv['id']}/projects")->assertOk()->assertJsonPath('data.sections.2.items.0.title', 'Tour Diamniadio');
    $this->postJson("/api/v1/cvs/{$cv['id']}/projects")->assertOk()->assertJsonCount(1, 'data.sections.2.items');

    $copy = $this->postJson("/api/v1/cvs/{$cv['id']}/duplicate")->assertCreated()->json('data');
    expect($copy['name'])->toBe('CV général (copie)')->and($copy['sections'])->toHaveCount(3);
    $this->getJson('/api/v1/me/cvs')->assertJsonCount(2, 'data');

    expect(substr($this->get("/api/v1/cvs/{$cv['id']}/pdf")->assertOk()->getContent(), 0, 4))->toBe('%PDF');
    $this->patchJson("/api/v1/cvs/{$cv['id']}", ['layout' => 'one_column', 'font' => 'serif']);
    expect(substr($this->get("/api/v1/cvs/{$cv['id']}/pdf")->assertOk()->getContent(), 0, 4))->toBe('%PDF');
});

it('ne montre un CV qu\'à son auteur, ou à un administrateur s\'il est partagé', function () {
    $owner = userWithRole('technician');
    $other = userWithRole('project_manager');
    $admin = userWithRole('admin');
    Sanctum::actingAs($owner);
    $id = $this->postJson('/api/v1/me/cvs', ['name' => 'Mon CV'])->json('data.id');

    Sanctum::actingAs($other);
    $this->getJson("/api/v1/cvs/{$id}")->assertForbidden();
    $this->patchJson("/api/v1/cvs/{$id}", ['name' => 'X'])->assertForbidden();

    Sanctum::actingAs($admin);
    $this->getJson("/api/v1/cvs/{$id}")->assertForbidden();
    $this->getJson("/api/v1/users/{$owner->id}/cvs")->assertOk()->assertJsonCount(0, 'data');

    Cv::find($id)->update(['shared' => true]);
    $this->getJson("/api/v1/cvs/{$id}")->assertOk()->assertJsonPath('data.can.update', false);
    $this->getJson("/api/v1/users/{$owner->id}/cvs")->assertJsonCount(1, 'data');
    $this->deleteJson("/api/v1/cvs/{$id}")->assertForbidden();

    Sanctum::actingAs($other);
    $this->getJson("/api/v1/users/{$owner->id}/cvs")->assertForbidden();
});

it('enregistre les préférences et ne prévient plus d\'une catégorie coupée', function () {
    $tech = userWithRole('technician');
    Sanctum::actingAs($tech);
    $this->getJson('/api/v1/auth/me')->assertJsonPath('data.preferences.theme', 'system')->assertJsonPath('data.preferences.notifications.tasks', true);

    $this->patchJson('/api/v1/me', ['preferences' => ['theme' => 'dark', 'notifications' => ['tasks' => false]]])
        ->assertOk()->assertJsonPath('data.preferences.theme', 'dark')
        ->assertJsonPath('data.preferences.notifications.tasks', false)->assertJsonPath('data.preferences.notifications.mentions', true);
    $this->patchJson('/api/v1/me', ['preferences' => ['theme' => 'violet']])->assertJsonValidationErrors('preferences.theme');
    $this->patchJson('/api/v1/me', ['preferences' => ['notifications' => ['banque' => false]]])->assertJsonValidationErrors('preferences.notifications');

    $task = \App\Models\Task::factory()->create();
    $tech->refresh()->notify(new \App\Notifications\TaskAssignedNotification($task, 'Moussa'));
    $tech->notify(new \App\Notifications\BackupFailedNotification('test'));
    expect($tech->notifications()->count())->toBe(1)->and($tech->notifications()->first()->data['kind'])->toBe('backup.failed');
});
