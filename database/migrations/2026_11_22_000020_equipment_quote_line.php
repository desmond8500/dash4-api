<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Appareil du chantier créé depuis une ligne de devis : évite de le créer deux fois. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equipment', function (Blueprint $table) {
            $table->foreignId('quote_line_id')->nullable()->after('article_id')->constrained('quote_lines')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('equipment', function (Blueprint $table) {
            $table->dropConstrainedForeignId('quote_line_id');
        });
    }
};
