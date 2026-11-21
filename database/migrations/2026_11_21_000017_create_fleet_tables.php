<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Parc automobile : véhicules et scooters, papiers, relevés de kilométrage, entretiens ; dépenses liées. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 20)->default('car');
            $table->string('name');
            $table->string('brand', 100)->nullable();
            $table->string('model', 100)->nullable();
            $table->string('plate', 30)->nullable()->unique();
            $table->string('vin', 50)->nullable();
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('color', 50)->nullable();
            $table->string('fuel', 20)->nullable();
            $table->unsignedSmallInteger('fiscal_power')->nullable();
            $table->unsignedTinyInteger('seats')->nullable();
            $table->string('status', 20)->default('active');
            $table->foreignId('driver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('acquired_on')->nullable();
            $table->bigInteger('purchase_price')->nullable();
            $table->unsignedInteger('mileage')->default(0);
            $table->unsignedInteger('service_interval_km')->nullable();
            $table->unsignedSmallInteger('service_interval_months')->nullable();
            $table->json('characteristics')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index('updated_at');
        });

        Schema::create('vehicle_papers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30);
            $table->string('label')->nullable();
            $table->string('number', 100)->nullable();
            $table->string('provider')->nullable();
            $table->date('issued_on')->nullable();
            $table->date('expires_on')->nullable();
            $table->bigInteger('cost')->nullable();
            $table->text('notes')->nullable();
            // Dernière alerte envoyée : 1 = à 30 jours, 2 = à 7 jours, 3 = expiré
            $table->unsignedTinyInteger('alert_level')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('expires_on');
        });

        Schema::create('vehicle_mileages', function (Blueprint $table) {
            $table->id();
            $table->uuid('client_uuid')->nullable()->unique();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->date('read_on');
            $table->unsignedInteger('km');
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['vehicle_id', 'read_on']);
        });

        Schema::create('vehicle_maintenances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30);
            $table->date('done_on');
            $table->unsignedInteger('km')->nullable();
            $table->text('description')->nullable();
            $table->string('garage')->nullable();
            $table->bigInteger('cost')->nullable();
            $table->date('next_on')->nullable();
            $table->unsignedInteger('next_km')->nullable();
            // Dernière alerte envoyée pour le prochain entretien : soon, puis late
            $table->string('alert_state', 10)->nullable();
            $table->foreignId('expense_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['vehicle_id', 'done_on']);
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->foreignId('vehicle_id')->nullable()->after('article_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('vehicle_id');
        });
        Schema::dropIfExists('vehicle_maintenances');
        Schema::dropIfExists('vehicle_mileages');
        Schema::dropIfExists('vehicle_papers');
        Schema::dropIfExists('vehicles');
    }
};
