<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Suivi de chantier, 2ᵉ partie : plans (image ou PDF) et marqueurs d'équipements ; quantitatifs
 * (articles et quantités par pièce, transformables en devis) ; fiches techniques et leurs zones.
 * Reprend `plans`, `plan_markers`, `quantitatifs` (+ lignes) et `fiches`, `fiche_zones` de Dash3.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('building_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('level_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('path');
            $table->string('mime', 100);
            $table->string('kind', 10)->default('image');
            $table->unsignedBigInteger('size')->default(0);
            $table->unsignedInteger('position')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('legacy_id')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('plan_markers', function (Blueprint $table) {
            $table->id();
            $table->uuid('client_uuid')->nullable()->unique();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('equipment_id')->nullable()->constrained('equipment')->nullOnDelete();
            $table->unsignedInteger('page')->default(1);
            // Position relative (0 à 1) : indépendante de la taille d'affichage
            $table->decimal('x', 8, 6);
            $table->decimal('y', 8, 6);
            $table->string('label')->nullable();
            $table->string('color', 20)->nullable();
            $table->unsignedBigInteger('legacy_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('quantities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('building_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('installed_system_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('quote_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('legacy_id')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('quantity_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quantity_id')->constrained()->cascadeOnDelete();
            $table->foreignId('article_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('level_id')->nullable()->constrained()->nullOnDelete();
            $table->string('designation');
            $table->string('unit', 20)->nullable();
            $table->decimal('quantity', 12, 2)->default(1);
            $table->text('description')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->unsignedBigInteger('legacy_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('sheets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('building_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('installed_system_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->string('type', 100)->nullable();
            $table->date('date')->nullable();
            $table->string('contact_name')->nullable();
            $table->string('contact_phone', 50)->nullable();
            $table->string('contact_email')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('legacy_id')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('sheet_zones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sheet_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->string('number', 50);
            $table->string('name');
            $table->string('code', 100)->nullable();
            $table->string('equipment_label')->nullable();
            $table->foreignId('equipment_id')->nullable()->constrained('equipment')->nullOnDelete();
            $table->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('legacy_id')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sheet_zones');
        Schema::dropIfExists('sheets');
        Schema::dropIfExists('quantity_rows');
        Schema::dropIfExists('quantities');
        Schema::dropIfExists('plan_markers');
        Schema::dropIfExists('plans');
    }
};
