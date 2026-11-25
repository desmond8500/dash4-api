<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Contacts liés à un projet ou à un devis (architecte, maître d'ouvrage, responsable sur place…), avec leur rôle. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('record_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->morphs('contactable');
            $table->string('role', 100)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['contact_id', 'contactable_type', 'contactable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('record_contacts');
    }
};
