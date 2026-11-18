<?php

use App\Models\ClientRequest;
use App\Notifications\ClientRequestNotification;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

it('relance une seule fois la personne en charge d\'une demande en retard', function () {
    Notification::fake();
    $chef = userWithRole('project_manager');
    ClientRequest::factory()->create(['assigned_to' => $chef->id, 'created_at' => now()->subDays(3)]);
    ClientRequest::factory()->create(['assigned_to' => $chef->id, 'created_at' => now()->subDay()]);

    $this->artisan('client-requests:remind')->assertSuccessful();
    $this->artisan('client-requests:remind')->assertSuccessful();

    Notification::assertSentToTimes($chef, ClientRequestNotification::class, 1);
    Notification::assertSentTo($chef, ClientRequestNotification::class, fn ($n) => $n->kind === 'overdue');
});

it('relance les administrateurs et managers quand personne n\'est en charge et que l\'auteur est technicien', function () {
    Notification::fake();
    $admin = userWithRole('admin');
    $manager = userWithRole('manager');
    $sales = userWithRole('sales');
    $tech = userWithRole('technician');
    ClientRequest::factory()->create(['created_by' => $tech->id, 'created_at' => now()->subDays(3)]);

    $this->artisan('client-requests:remind')->assertSuccessful();

    Notification::assertSentTo([$admin, $manager], ClientRequestNotification::class);
    Notification::assertNotSentTo([$sales, $tech], ClientRequestNotification::class);
});

it('envoie le rappel à la date choisie, et de nouveau si la date change', function () {
    Notification::fake();
    $sales = userWithRole('sales');
    $request = ClientRequest::factory()->create(['created_by' => $sales->id, 'status' => 'in_progress', 'remind_at' => now()->addHour()]);

    $this->artisan('client-requests:remind');
    Notification::assertNothingSent();

    $this->travel(2)->hours();
    $this->artisan('client-requests:remind');
    Notification::assertSentToTimes($sales, ClientRequestNotification::class, 1);

    $request->fresh()->update(['remind_at' => now()->addMinute()]);
    $this->travel(5)->minutes();
    $this->artisan('client-requests:remind');
    Notification::assertSentToTimes($sales, ClientRequestNotification::class, 2);
});

it('ne relance pas une demande traitée', function () {
    Notification::fake();
    userWithRole('admin');
    ClientRequest::factory()->create(['status' => 'done', 'created_at' => now()->subDays(5), 'remind_at' => now()->subDay()]);

    $this->artisan('client-requests:remind');

    Notification::assertNothingSent();
});

it('montre les relances dans les notifications de l\'utilisateur', function () {
    $chef = userWithRole('project_manager');
    ClientRequest::factory()->create(['body' => 'Installer deux caméras', 'assigned_to' => $chef->id, 'created_at' => now()->subDays(3)]);
    $this->artisan('client-requests:remind');
    Sanctum::actingAs($chef);

    $response = $this->getJson('/api/v1/notifications')->assertOk()
        ->assertJsonPath('meta.unread_count', 1)
        ->assertJsonPath('data.0.kind', 'client_request.overdue')
        ->assertJsonPath('data.0.title', 'Demande en attente depuis plus de 2 jours');
    expect($response->json('data.0.body'))->toContain('Installer deux caméras');

    $this->postJson('/api/v1/notifications/'.$response->json('data.0.id').'/read')->assertOk();
    $this->getJson('/api/v1/notifications?unread=1')->assertJsonCount(0, 'data')->assertJsonPath('meta.unread_count', 0);
});

it('ne laisse pas lire les notifications d\'un autre utilisateur', function () {
    $chef = userWithRole('project_manager');
    ClientRequest::factory()->create(['assigned_to' => $chef->id, 'created_at' => now()->subDays(3)]);
    $this->artisan('client-requests:remind');
    $id = $chef->notifications()->first()->id;

    Sanctum::actingAs(userWithRole('sales'));
    $this->postJson("/api/v1/notifications/{$id}/read")->assertNotFound();
    $this->postJson('/api/v1/notifications/read-all')->assertOk();

    expect($chef->fresh()->unreadNotifications()->count())->toBe(1);
});
