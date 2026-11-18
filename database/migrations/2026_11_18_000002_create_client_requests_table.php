<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Demandes clients notées rapidement pour être traitées plus tard (TODO §3).
 * Reprend la table `tickets` de Dash3, jamais utilisée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('client_uuid')->nullable()->unique();
            $table->text('body');
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            // Personne qui n'est pas encore cliente
            $table->string('contact_name')->nullable();
            $table->string('contact_phone', 50)->nullable();
            $table->string('source', 20)->default('phone');
            $table->string('status', 20)->default('new');
            $table->string('priority', 10)->default('normal');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('remind_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            // Ce que la demande est devenue (tâche, devis, projet, intervention), quand ces modules existeront
            $table->nullableMorphs('converted_to');
            $table->timestamp('overdue_notified_at')->nullable();
            $table->timestamp('reminder_notified_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('legacy_id')->nullable()->unique();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'created_at']);
            $table->index('updated_at');
        });

        if (! Schema::hasTable('notifications')) {
            Schema::create('notifications', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('type');
                $table->morphs('notifiable');
                $table->text('data');
                $table->timestamp('read_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('client_requests');
        Schema::dropIfExists('notifications');
    }
};
