<?php

use App\Support\Backup\Backups;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
});

it('sauvegarde la base et les fichiers, et les administrateurs seuls y accèdent', function () {
    $admin = userWithRole('admin');
    Storage::disk('local')->put('documents/plan.pdf', 'plan');
    Storage::disk('public')->put('clients/logos/logo.png', 'logo');

    $this->artisan('backup:run', ['--files' => true])->assertSuccessful();
    $list = Backups::list();
    expect($list)->toHaveCount(2)->and(collect($list)->pluck('kind')->sort()->values()->all())->toBe(['database', 'files']);

    $sql = gzdecode(Storage::disk('local')->get(Backups::path(collect($list)->firstWhere('kind', 'database')['name'])));
    expect($sql)->toContain('CREATE TABLE')->toContain('INSERT INTO "users"')->toContain($admin->email);
    $zip = new ZipArchive;
    $zip->open(Storage::disk('local')->path(Backups::path(collect($list)->firstWhere('kind', 'files')['name'])));
    expect($zip->locateName('prives/documents/plan.pdf'))->not->toBeFalse()
        ->and($zip->locateName('publics/clients/logos/logo.png'))->not->toBeFalse();
    $zip->close();

    foreach (['manager', 'accountant', 'technician'] as $role) {
        Sanctum::actingAs(userWithRole($role));
        $this->getJson('/api/v1/backups')->assertForbidden();
    }

    Sanctum::actingAs($admin);
    $this->getJson('/api/v1/backups')->assertOk()->assertJsonCount(2, 'data');
    $name = $list[0]['name'];
    $this->get("/api/v1/backups/{$name}")->assertOk();
    $this->get('/api/v1/backups/..%2F.env')->assertNotFound();
    $this->deleteJson("/api/v1/backups/{$name}")->assertOk();
    $this->getJson('/api/v1/backups')->assertJsonCount(1, 'data');
});

it('supprime les sauvegardes trop anciennes en gardant la dernière', function () {
    foreach (['20260101-023000', '20260102-023000'] as $stamp) {
        Storage::disk('local')->put("backups/dash4-base-{$stamp}.sql.gz", 'x');
    }
    Storage::disk('local')->put('backups/autre-fichier.txt', 'x');
    expect(Backups::prune(14))->toBe(1);
    expect(collect(Backups::list())->pluck('name')->all())->toBe(['dash4-base-20260102-023000.sql.gz']);
});
