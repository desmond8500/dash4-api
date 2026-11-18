<?php

use App\Models\BankAccount;
use App\Models\Company;
use App\Notifications\CompanyChangeNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

it('crée la fiche de l\'entreprise au premier accès et la laisse modifier par le comptable', function () {
    Sanctum::actingAs(userWithRole('accountant'));

    $this->getJson('/api/v1/company')->assertOk()->assertJsonPath('data.country', 'Sénégal');

    $this->patchJson('/api/v1/company', [
        'name' => 'Yonkou SARL', 'legal_form' => 'SARL', 'ninea' => '0012345 2G3', 'rccm' => 'SN-DKR-2020-B-1234',
        'email' => 'contact@yonkou.info', 'website' => 'https://yonkou.info',
    ])->assertOk()->assertJsonPath('data.name', 'Yonkou SARL')->assertJsonPath('data.ninea', '0012345 2G3');

    expect(Company::count())->toBe(1);
});

it('laisse le manager consulter l\'entreprise sans la modifier', function () {
    Sanctum::actingAs(userWithRole('manager'));

    $this->getJson('/api/v1/company')->assertOk()->assertJsonMissingPath('data.signature');
    $this->patchJson('/api/v1/company', ['name' => 'X'])->assertForbidden();
});

it('refuse l\'entreprise au technicien et au commercial', function () {
    foreach (['technician', 'sales'] as $role) {
        Sanctum::actingAs(userWithRole($role));
        $this->getJson('/api/v1/company')->assertForbidden();
    }
});

it('garde la signature et le cachet en privé, en PNG seulement, et prévient les administrateurs', function () {
    Storage::fake('local');
    Notification::fake();
    $admin = userWithRole('admin');
    $accountant = userWithRole('accountant');
    Sanctum::actingAs($accountant);

    $this->post('/api/v1/company/signature', ['image' => UploadedFile::fake()->image('s.jpg')], ['Accept' => 'application/json'])
        ->assertJsonPath('errors.image.0', "L'image doit être au format PNG (fond transparent conseillé).");

    $this->post('/api/v1/company/signature', ['image' => UploadedFile::fake()->image('s.png', 400, 150)], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('data.signature.has_signature', true)
        ->assertJsonPath('data.signature.has_stamp', false);

    $path = Company::current()->signature_path;
    Storage::disk('local')->assertExists($path);
    $this->get('/api/v1/company/signature')->assertOk()->assertHeader('Content-Type', 'image/png');
    Notification::assertSentTo($admin, CompanyChangeNotification::class, fn ($n) => $n->kind === 'signature');

    // Le manager ne voit pas la signature
    Sanctum::actingAs(userWithRole('manager'));
    $this->getJson('/api/v1/company/signature')->assertForbidden();
    $this->post('/api/v1/company/stamp', ['image' => UploadedFile::fake()->image('c.png')], ['Accept' => 'application/json'])->assertForbidden();
});

it('choisit les documents où apparaît la signature (signature.manage)', function () {
    Sanctum::actingAs(userWithRole('accountant'));

    $this->patchJson('/api/v1/company', ['signature_on' => ['report' => false, 'inconnu' => true]])
        ->assertOk()
        ->assertJsonPath('data.signature.on.report', false)
        ->assertJsonPath('data.signature.on.quote', true)
        ->assertJsonMissingPath('data.signature.on.inconnu');
});

it('chiffre les numéros de compte en base et ne les montre qu\'avec bank.view', function () {
    Notification::fake();
    $admin = userWithRole('admin');
    Sanctum::actingAs(userWithRole('accountant'));

    $this->postJson('/api/v1/bank-accounts', [
        'bank_name' => 'CBAO', 'holder' => 'Yonkou SARL', 'account_number' => 'SN012 01001 036213452001 75', 'bic' => 'CBAOSNDA',
    ])->assertCreated()->assertJsonPath('data.is_default', true)->assertJsonPath('data.account_number', 'SN012 01001 036213452001 75');

    $raw = DB::table('bank_accounts')->value('account_number');
    expect($raw)->not->toContain('036213452001');
    Notification::assertSentTo($admin, CompanyChangeNotification::class);

    Sanctum::actingAs(userWithRole('sales'));
    $this->getJson('/api/v1/bank-accounts')->assertForbidden();

    Sanctum::actingAs(userWithRole('technician'));
    $this->getJson('/api/v1/bank-accounts')->assertForbidden();
});

it('garde un seul compte par défaut', function () {
    Sanctum::actingAs(userWithRole('accountant'));
    $a = $this->postJson('/api/v1/bank-accounts', ['bank_name' => 'CBAO', 'holder' => 'Y', 'account_number' => '1'])->json('data.id');
    $b = $this->postJson('/api/v1/bank-accounts', ['bank_name' => 'Ecobank', 'holder' => 'Y', 'account_number' => '2', 'is_default' => true])
        ->assertJsonPath('data.is_default', true)->json('data.id');

    expect(BankAccount::find($a)->is_default)->toBeFalse();

    // Supprimer le compte par défaut : le suivant le devient
    $this->deleteJson("/api/v1/bank-accounts/{$b}")->assertOk();
    expect(BankAccount::find($a)->is_default)->toBeTrue();

    $this->postJson('/api/v1/bank-accounts', ['bank_name' => 'X', 'holder' => 'Y', 'account_number' => '3', 'bic' => 'ABC'])
        ->assertJsonPath('errors.bic.0', 'Le code BIC / SWIFT doit contenir 8 ou 11 lettres et chiffres.');
});

it('note au journal un changement de numéro de compte, sans le numéro en clair', function () {
    Sanctum::actingAs(userWithRole('accountant'));
    $id = $this->postJson('/api/v1/bank-accounts', ['bank_name' => 'CBAO', 'holder' => 'Y', 'account_number' => '111'])->json('data.id');

    $this->patchJson("/api/v1/bank-accounts/{$id}", ['account_number' => '999888777'])->assertOk();

    $logs = DB::table('activity_log')->where('log_name', 'bank')->pluck('description')->all();
    expect($logs)->toContain('Numéro de compte ou IBAN modifié');
    expect(DB::table('activity_log')->where('properties', 'like', '%999888777%')->exists())->toBeFalse();
});

it('réserve la modification de la banque à bank.manage', function () {
    Sanctum::actingAs(userWithRole('manager'));

    $this->postJson('/api/v1/bank-accounts', ['bank_name' => 'CBAO', 'holder' => 'Y', 'account_number' => '1'])->assertForbidden();
});
