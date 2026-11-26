<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Prix d'achat en euro ou en dollar d'un article acheté à l'étranger : son coût en F CFA en est calculé. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->decimal('foreign_price', 14, 2)->nullable()->after('coefficient');
            $table->string('foreign_currency', 3)->nullable()->after('foreign_price');
        });
    }

    public function down(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->dropColumn(['foreign_price', 'foreign_currency']);
        });
    }
};
