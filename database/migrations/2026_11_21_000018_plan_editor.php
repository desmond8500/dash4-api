<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Éditeur de plans : bibliothèque d'icônes (symboles intégrés ou images), icône par article,
 * repères redimensionnables et pivotables rangés par système (calques), réglages et aperçus des plans pour les PDF.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_icons', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('category', 100)->nullable();
            // Symbole intégré (camera, smoke…) ou image envoyée (PNG, SVG, WebP)
            $table->string('symbol', 40)->nullable();
            $table->string('color', 20)->nullable();
            $table->string('path')->nullable();
            $table->string('mime', 100)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('articles', function (Blueprint $table) {
            $table->foreignId('plan_icon_id')->nullable()->after('storage_location_id')->constrained()->nullOnDelete();
        });

        Schema::table('plan_markers', function (Blueprint $table) {
            $table->foreignId('plan_icon_id')->nullable()->after('equipment_id')->constrained()->nullOnDelete();
            $table->foreignId('installed_system_id')->nullable()->after('plan_icon_id')->constrained()->nullOnDelete();
            // Taille : part de la largeur du plan ; rotation en degrés
            $table->decimal('size', 6, 4)->default(0.03)->after('y');
            $table->smallInteger('rotation')->default(0)->after('size');
            $table->boolean('show_label')->default(true)->after('label');
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->json('settings')->nullable()->after('position');
            $table->json('renders')->nullable()->after('settings');
        });
    }

    public function down(): void
    {
        Schema::table('plans', fn (Blueprint $t) => $t->dropColumn(['settings', 'renders']));
        Schema::table('plan_markers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('plan_icon_id');
            $table->dropConstrainedForeignId('installed_system_id');
            $table->dropColumn(['size', 'rotation', 'show_label']);
        });
        Schema::table('articles', fn (Blueprint $t) => $t->dropConstrainedForeignId('plan_icon_id'));
        Schema::dropIfExists('plan_icons');
    }
};
