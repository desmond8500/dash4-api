<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Entreprise (mono-société : une seule ligne), comptes bancaires (chiffrés), paramètres
 * (TVA, BRS, devise, numérotation) et compteurs de numérotation des documents.
 * Dash3 gardait ces informations dans le .env (APP_NAME, NINEA, RIB…).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('legal_form', 100)->nullable();
            $table->string('logo_path')->nullable();
            $table->string('address')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('country', 100)->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('email')->nullable();
            $table->string('website')->nullable();
            $table->string('ninea', 50)->nullable();
            $table->string('rccm', 100)->nullable();
            $table->string('share_capital', 100)->nullable();
            $table->text('legal_mentions')->nullable();
            $table->text('terms')->nullable();
            // Signature et cachet en image (disque privé), et documents où ils apparaissent
            $table->string('signature_path')->nullable();
            $table->string('stamp_path')->nullable();
            $table->json('signature_on')->nullable();
            $table->timestamps();
        });

        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('label')->nullable();
            $table->string('bank_name');
            $table->string('holder');
            // Chiffrés en base (cast encrypted) : colonnes texte
            $table->text('account_number');
            $table->text('iban')->nullable();
            $table->string('bic', 20)->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->string('key', 100)->primary();
            $table->json('value')->nullable();
            $table->timestamps();
        });

        Schema::create('document_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('type', 30);
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();

            $table->unique(['type', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_sequences');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('bank_accounts');
        Schema::dropIfExists('company');
    }
};
