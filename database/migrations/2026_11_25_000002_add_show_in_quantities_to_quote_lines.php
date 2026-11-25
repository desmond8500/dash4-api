<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Lignes retirées du quantitatif (PDF et tableur), au choix de l'utilisateur ; les forfaits n'y sont jamais. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quote_lines', function (Blueprint $table) {
            $table->boolean('show_in_quantities')->default(true)->after('comment');
        });
    }

    public function down(): void
    {
        Schema::table('quote_lines', function (Blueprint $table) {
            $table->dropColumn('show_in_quantities');
        });
    }
};
