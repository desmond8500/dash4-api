<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Photo principale des véhicules et des outils (les autres photos sont des documents image de la fiche). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->string('photo_path')->nullable()->after('notes');
        });
        Schema::table('tools', function (Blueprint $table) {
            $table->string('photo_path')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', fn (Blueprint $table) => $table->dropColumn('photo_path'));
        Schema::table('tools', fn (Blueprint $table) => $table->dropColumn('photo_path'));
    }
};
