<?php

use App\Models\Expense;
use App\Models\Vehicle;
use App\Notifications\FleetNotification;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

it('gère un véhicule : caractéristiques, papiers, kilométrage et entretiens', function () {
    Sanctum::actingAs(userWithRole('manager'));

    $vehicle = $this->postJson('/api/v1/vehicles', [
        'kind' => 'scooter', 'name' => 'Scooter livraison', 'brand' => 'Yamaha', 'plate' => 'DK-1234-AB', 'fuel' => 'petrol',
        'mileage' => 12000, 'service_interval_km' => 2500, 'purchase_price' => 950000,
        'characteristics' => [['label' => 'Cylindrée', 'value' => '125 cm³']],
    ])->assertCreated()->assertJsonPath('data.mileage', 12000)->assertJsonPath('data.kind.label', 'Scooter')->json('data');

    $this->postJson("/api/v1/vehicles/{$vehicle['id']}/papers", ['type' => 'insurance', 'provider' => 'AXA', 'expires_on' => today()->addDays(10)->toDateString(), 'cost' => 85000])
        ->assertCreated()->assertJsonPath('data.papers.0.state', 'soon')->assertJsonPath('data.alerts.0.kind', 'paper');

    $this->postJson("/api/v1/vehicles/{$vehicle['id']}/mileages", ['km' => 14000])->assertCreated()->assertJsonPath('data.mileage', 14000);
    $this->postJson("/api/v1/vehicles/{$vehicle['id']}/mileages", ['km' => 13000])->assertStatus(422);

    // Vidange à 12 000 km sans prochain entretien saisi : prochaine calculée à 14 500 km (intervalle), donc proche
    $data = $this->postJson("/api/v1/vehicles/{$vehicle['id']}/maintenances", [
        'type' => 'oil_change', 'done_on' => today()->subMonth()->toDateString(), 'km' => 12000, 'cost' => 15000, 'create_expense' => true,
    ])->assertCreated()->json('data');
    expect($data['upcoming'][0])->toMatchArray(['type' => 'oil_change', 'next_km' => 14500, 'state' => 'soon'])
        ->and($data['costs'])->toMatchArray(['papers' => 85000, 'maintenance' => 0, 'expenses' => 15000, 'total' => 100000]);
    expect(Expense::query()->where('vehicle_id', $vehicle['id'])->value('amount'))->toBe(15000);

    $this->getJson('/api/v1/vehicles?alerts=1')->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/expenses?vehicle_id='.$vehicle['id'])->assertJsonPath('data.0.vehicle.plate', 'DK-1234-AB');
});

it('montre au conducteur technicien son véhicule, sans aucun coût, et le laisse relever le compteur', function () {
    $tech = userWithRole('technician');
    $mine = Vehicle::query()->forceCreate(['name' => 'Moto Awa', 'kind' => 'motorcycle', 'driver_id' => $tech->id, 'purchase_price' => 700000]);
    $mine->papers()->forceCreate(['vehicle_id' => $mine->id, 'type' => 'cmc', 'cost' => 20000, 'expires_on' => today()->addYear()]);
    $other = Vehicle::query()->forceCreate(['name' => 'Pick-up']);
    Sanctum::actingAs($tech);

    $this->getJson('/api/v1/vehicles')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine->id);
    $json = $this->getJson("/api/v1/vehicles/{$mine->id}")->assertOk()->json('data');
    expect($json)->not->toHaveKey('purchase_price')->not->toHaveKey('costs')
        ->and($json['papers'][0])->not->toHaveKey('cost');
    $this->getJson("/api/v1/vehicles/{$other->id}")->assertForbidden();

    $uuid = (string) \Illuminate\Support\Str::uuid();
    $this->postJson("/api/v1/vehicles/{$mine->id}/mileages", ['km' => 5300, 'client_uuid' => $uuid])->assertCreated();
    $this->postJson("/api/v1/vehicles/{$mine->id}/mileages", ['km' => 5300, 'client_uuid' => $uuid])->assertOk();
    expect($mine->mileages()->count())->toBe(1);
    $this->patchJson("/api/v1/vehicles/{$mine->id}", ['name' => 'X'])->assertForbidden();
    $this->postJson("/api/v1/vehicles/{$mine->id}/maintenances", ['type' => 'repair', 'done_on' => today()->toDateString()])->assertForbidden();
});

it('prévient chaque papier une fois à 30 jours, à 7 jours puis à l\'expiration', function () {
    Notification::fake();
    $manager = userWithRole('manager');
    $vehicle = Vehicle::query()->forceCreate(['name' => 'Berlingo', 'plate' => 'DK-1']);
    $paper = $vehicle->papers()->forceCreate(['vehicle_id' => $vehicle->id, 'type' => 'inspection', 'expires_on' => today()->addDays(20)]);

    $this->artisan('fleet:check')->assertSuccessful();
    $this->artisan('fleet:check')->assertSuccessful();
    Notification::assertSentToTimes($manager, FleetNotification::class, 1);

    $paper->forceFill(['expires_on' => today()->addDays(5)])->save();
    $this->artisan('fleet:check');
    $paper->forceFill(['expires_on' => today()->subDay()])->save();
    $this->artisan('fleet:check');
    $this->artisan('fleet:check');
    Notification::assertSentToTimes($manager, FleetNotification::class, 3);
    expect($paper->fresh()->alert_level)->toBe(3);
});

it('n\'alerte que sur le papier le plus récent de chaque type', function () {
    $vehicle = Vehicle::query()->forceCreate(['name' => 'C3']);
    $vehicle->papers()->forceCreate(['vehicle_id' => $vehicle->id, 'type' => 'insurance', 'expires_on' => today()->subMonth()]);
    $vehicle->papers()->forceCreate(['vehicle_id' => $vehicle->id, 'type' => 'insurance', 'expires_on' => today()->addYear()]);

    expect(\App\Support\Fleet::alerts($vehicle->load('papers', 'maintenances')))->toBe([]);
});
