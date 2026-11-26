<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Techniciens affectés à un projet : ils en voient la partie chantier (et rien d'autre du projet). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_technicians', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['project_id', 'user_id'], 'project_technicians_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_technicians');
    }
};
