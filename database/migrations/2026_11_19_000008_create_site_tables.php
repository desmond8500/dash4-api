<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Suivi de chantier : bâtiments → niveaux → pièces ; catalogue des systèmes ; systèmes installés
 * par projet (avec leur avancement daté) et leurs équipements ; procès-verbaux de réception.
 * Reprend `buildings`, `stages`, `rooms`, `systemes`, `installation_systemes`, `equipements`, `pvs` de Dash3.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buildings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->unsignedBigInteger('legacy_id')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('building_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->unsignedBigInteger('legacy_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('level_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->unsignedBigInteger('legacy_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('systems', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('icon', 60)->nullable();
            $table->string('description')->nullable();
            $table->unsignedBigInteger('legacy_id')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('installed_systems', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('building_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('system_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedTinyInteger('progress')->default(0);
            $table->unsignedInteger('position')->default(0);
            $table->unsignedBigInteger('legacy_id')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();
        });

        // Avancement daté d'un système installé : chaque relevé garde le pourcentage et une note
        Schema::create('system_progress', function (Blueprint $table) {
            $table->id();
            $table->uuid('client_uuid')->nullable()->unique();
            $table->foreignId('installed_system_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedTinyInteger('percent');
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('equipment', function (Blueprint $table) {
            $table->id();
            $table->uuid('client_uuid')->nullable()->unique();
            $table->foreignId('installed_system_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('equipment')->cascadeOnDelete();
            $table->foreignId('article_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('level_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('type', 100)->nullable();
            $table->string('model')->nullable();
            $table->string('serial_number')->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->string('status', 20)->default('planned');
            $table->json('characteristics')->nullable();
            $table->text('description')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->unsignedBigInteger('legacy_id')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('reception_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quote_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->string('subtitle')->nullable();
            $table->date('date');
            $table->string('status', 20)->default('draft');
            $table->string('location')->nullable();
            // Parties : client, maître d'ouvrage, maître d'œuvre, bureau de contrôle (noms et logos)
            foreach (['client', 'owner', 'engineer', 'inspector'] as $party) {
                $table->string("{$party}_name")->nullable();
                $table->string("{$party}_logo_path")->nullable();
            }
            $table->text('observations')->nullable();
            // Réserves : [{ text, lifted_at }]
            $table->json('reserves')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('legacy_id')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('installed_system_reception_report', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reception_report_id')->constrained()->cascadeOnDelete();
            $table->foreignId('installed_system_id')->constrained()->cascadeOnDelete();
            $table->unique(['reception_report_id', 'installed_system_id'], 'report_system_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('installed_system_reception_report');
        Schema::dropIfExists('reception_reports');
        Schema::dropIfExists('equipment');
        Schema::dropIfExists('system_progress');
        Schema::dropIfExists('installed_systems');
        Schema::dropIfExists('systems');
        Schema::dropIfExists('rooms');
        Schema::dropIfExists('levels');
        Schema::dropIfExists('buildings');
    }
};
