<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Espace personnel : CV de chaque utilisateur (plusieurs versions), sections et éléments. Reprend les CV de Dash3. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cvs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('title')->nullable();
            $table->string('fullname')->nullable();
            $table->text('summary')->nullable();
            $table->string('photo_path')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->string('website')->nullable();
            $table->string('accent_color', 20)->default('#1690b7');
            $table->string('font', 20)->default('sans');
            $table->string('layout', 20)->default('two_columns');
            $table->boolean('show_photo')->default(true);
            // Partagé : visible aussi par les administrateurs
            $table->boolean('shared')->default(false);
            $table->unsignedBigInteger('legacy_id')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('cv_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cv_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type', 30)->default('free');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('cv_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cv_section_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('subtitle')->nullable();
            $table->string('place')->nullable();
            $table->string('start_date', 20)->nullable();
            $table->string('end_date', 20)->nullable();
            $table->boolean('current')->default(false);
            $table->text('description')->nullable();
            $table->unsignedTinyInteger('level')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cv_items');
        Schema::dropIfExists('cv_sections');
        Schema::dropIfExists('cvs');
    }
};
