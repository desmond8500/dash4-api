<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Icônes à code comme sur les légendes des plans (DOF, CDI, NC2…) : texte dans la forme, couleur du texte,
 * seconde couleur (moitié basse : extincteurs), clé de la bibliothèque standard et ordre d'affichage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plan_icons', function (Blueprint $table) {
            $table->string('slug', 60)->nullable()->unique()->after('id');
            $table->string('code', 6)->nullable()->after('symbol');
            $table->string('text_color', 20)->nullable()->after('color');
            $table->string('secondary_color', 20)->nullable()->after('text_color');
            $table->unsignedSmallInteger('position')->default(0)->after('secondary_color');
        });
    }

    public function down(): void
    {
        Schema::table('plan_icons', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->dropColumn(['slug', 'code', 'text_color', 'secondary_color', 'position']);
        });
    }
};
