<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Contacts liés à un projet ou à un devis (architecte, maître d'ouvrage, responsable sur place…), avec leur rôle. */
return new class extends Migration
{
    /** Nom court : MariaDB limite les noms d'index à 64 caractères. */
    private const UNIQUE = 'record_contacts_unique';

    public function up(): void
    {
        // Un premier essai a pu créer la table sans son index (nom trop long, MariaDB n'annule pas un CREATE) :
        // on la complète au lieu d'échouer.
        if (Schema::hasTable('record_contacts')) {
            if (! Schema::hasIndex('record_contacts', self::UNIQUE)) {
                Schema::table('record_contacts', function (Blueprint $table) {
                    $table->unique(['contact_id', 'contactable_type', 'contactable_id'], self::UNIQUE);
                });
            }

            return;
        }

        Schema::create('record_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->morphs('contactable');
            $table->string('role', 100)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['contact_id', 'contactable_type', 'contactable_id'], self::UNIQUE);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('record_contacts');
    }
};
