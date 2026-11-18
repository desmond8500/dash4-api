<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            // Identifiant créé par l'app mobile hors ligne (anti-doublon)
            $table->uuid('client_uuid')->nullable()->unique();
            $table->string('name');
            $table->string('type', 30)->default('company')->index();
            $table->string('status', 20)->default('active')->index();
            $table->text('description')->nullable();
            $table->string('address')->nullable();
            $table->string('logo_path')->nullable();
            $table->boolean('is_favorite')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            // Identifiant dans Dash3, pour la reprise des données
            $table->unsignedBigInteger('legacy_id')->nullable()->unique();
            $table->timestamps();
            $table->softDeletes();

            $table->index('name');
            $table->index('updated_at');
        });

        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->uuid('client_uuid')->nullable()->unique();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('civility', 10)->nullable();
            $table->string('firstname');
            $table->string('lastname')->nullable();
            $table->string('job_title')->nullable();
            // Société, quand le contact n'est rattaché à aucun client
            $table->string('company')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('legacy_id')->nullable()->unique();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['lastname', 'firstname']);
            $table->index('updated_at');
        });

        // Numéros supplémentaires, rattachables à toute fiche (client, contact, fournisseur…)
        Schema::create('phones', function (Blueprint $table) {
            $table->id();
            $table->morphs('phoneable');
            $table->string('label', 50)->nullable();
            $table->string('number', 50);
            $table->timestamps();
        });

        // Adresses e-mail supplémentaires, rattachables à toute fiche
        Schema::create('emails', function (Blueprint $table) {
            $table->id();
            $table->morphs('emailable');
            $table->string('label', 50)->nullable();
            $table->string('address');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emails');
        Schema::dropIfExists('phones');
        Schema::dropIfExists('contacts');
        Schema::dropIfExists('clients');
    }
};
