<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Outillage : chaque outil est un exemplaire, lié à un article du stock ; prêts avec date de retour et rappels. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tools', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            // Numéro d'inventaire (marqué sur l'outil)
            $table->string('code', 50)->nullable()->unique();
            $table->string('serial_number', 100)->nullable();
            // good, worn, repair, broken, lost, retired
            $table->string('condition', 20)->default('good');
            $table->foreignId('storage_location_id')->nullable()->constrained()->nullOnDelete();
            $table->date('acquired_on')->nullable();
            $table->bigInteger('purchase_value')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index('updated_at');
        });

        Schema::create('tool_loans', function (Blueprint $table) {
            $table->id();
            $table->uuid('client_uuid')->nullable()->unique();
            $table->foreignId('tool_id')->constrained()->cascadeOnDelete();
            // Emprunteur : un utilisateur de Dash4, ou un nom libre (sous-traitant, client…)
            $table->foreignId('borrower_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('borrower_name')->nullable();
            $table->string('borrower_phone', 50)->nullable();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('lent_at');
            $table->date('due_on')->nullable();
            $table->dateTime('returned_at')->nullable();
            $table->string('return_condition', 20)->nullable();
            $table->text('notes')->nullable();
            $table->text('return_notes')->nullable();
            // Rappels : 1 = la veille, 2 = le jour du retour (ou en retard), puis tous les 3 jours de retard
            $table->unsignedTinyInteger('reminder_level')->default(0);
            $table->date('last_reminded_on')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('returned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['tool_id', 'returned_at']);
            $table->index(['returned_at', 'due_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tool_loans');
        Schema::dropIfExists('tools');
    }
};
