<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Signatures des e-mails envoyés depuis Dash4 : celles de chaque utilisateur (user_id),
 * et celles communes à l'entreprise (user_id vide, gérées avec company.update).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_signatures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->text('body');
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['user_id', 'is_default']);
        });

        Schema::table('quote_emails', function (Blueprint $table) {
            $table->foreignId('email_signature_id')->nullable()->after('message')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('quote_emails', fn (Blueprint $table) => $table->dropConstrainedForeignId('email_signature_id'));
        Schema::dropIfExists('email_signatures');
    }
};
