<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Espace technicien : informations temporaires (codes d'accès, matériel, consignes) envoyées
 * à des destinataires et effacées à leur expiration ; demandes d'accès des techniciens.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tech_shares', function (Blueprint $table) {
            $table->id();
            $table->uuid('client_uuid')->nullable()->unique();
            $table->string('category', 20)->default('info');
            $table->string('title');
            $table->text('content')->nullable();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('task_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('expires_at')->nullable()->index();
            $table->boolean('expires_with_task')->default(false);
            $table->timestamp('expired_at')->nullable()->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('legacy_id')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('tech_share_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tech_share_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->unique(['tech_share_id', 'user_id']);
        });

        Schema::create('tech_access_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('client_uuid')->nullable()->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('subject');
            $table->text('reason')->nullable();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('task_id')->nullable()->constrained()->nullOnDelete();
            $table->date('needed_on')->nullable();
            $table->string('status', 20)->default('pending')->index();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('response')->nullable();
            $table->foreignId('tech_share_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('legacy_id')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tech_access_requests');
        Schema::dropIfExists('tech_share_user');
        Schema::dropIfExists('tech_shares');
    }
};
