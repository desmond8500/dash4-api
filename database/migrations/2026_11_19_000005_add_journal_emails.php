<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Historique des e-mails : un rapport d'intervention (journal) peut aussi être envoyé. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quote_emails', function (Blueprint $table) {
            $table->foreignId('journal_id')->nullable()->after('invoice_id')->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('quote_emails', fn (Blueprint $table) => $table->dropConstrainedForeignId('journal_id'));
    }
};
