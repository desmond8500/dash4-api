<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Boîte de réception : e-mails relevés sur l'adresse dédiée (IMAP), à transformer en demandes.
 * Une demande peut être liée à plusieurs fiches : tâches, factures, bordereaux, devis, projets.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbound_emails', function (Blueprint $table) {
            $table->id();
            $table->string('message_id')->nullable()->unique();
            $table->string('from_email')->nullable()->index();
            $table->string('from_name')->nullable();
            $table->string('to')->nullable();
            $table->string('subject');
            $table->longText('body_text')->nullable();
            $table->longText('body_html')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->string('status', 20)->default('new');
            $table->json('attachments')->nullable();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('client_request_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status', 'received_at']);
        });

        Schema::table('client_requests', function (Blueprint $table) {
            $table->string('contact_email')->nullable()->after('contact_phone');
        });

        Schema::create('client_request_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_request_id')->constrained()->cascadeOnDelete();
            $table->morphs('linkable');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['client_request_id', 'linkable_type', 'linkable_id'], 'client_request_links_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_request_links');
        Schema::table('client_requests', fn (Blueprint $table) => $table->dropColumn('contact_email'));
        Schema::dropIfExists('inbound_emails');
    }
};
