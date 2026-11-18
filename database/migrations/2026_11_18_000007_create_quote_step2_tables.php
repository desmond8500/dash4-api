<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Devis, étape 2 (Dash3 : invoice_systems, invoice_models, invoice_model_rows, invoice_proposals,
 * invoice_acomptes, invoice_spents, invoice_bls) et historique des envois par e-mail.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Systèmes (vidéosurveillance, alarme…) et modèles de lignes à insérer dans un devis
        Schema::create('quote_systems', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('icon', 50)->nullable();
            $table->unsignedBigInteger('legacy_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('quote_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quote_system_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('icon', 50)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('legacy_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('quote_template_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quote_template_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->unsignedBigInteger('article_id')->nullable();
            $table->string('designation');
            $table->text('reference')->nullable();
            $table->string('category', 20)->default('other');
            $table->string('unit', 20)->nullable();
            $table->decimal('quantity', 12, 2)->default(1);
            $table->decimal('unit_cost', 15, 2)->default(0);
            $table->decimal('coefficient', 8, 4)->default(1);
            $table->timestamps();
        });

        // Proposition technique : texte par section, options d'affichage par devis
        Schema::table('quote_sections', function (Blueprint $table) {
            $table->text('proposal')->nullable()->after('is_visible');
        });
        Schema::table('quotes', function (Blueprint $table) {
            $table->json('proposal_options')->nullable()->after('show_signature');
        });

        // Acomptes demandés au client
        Schema::create('quote_deposits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quote_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->text('description')->nullable();
            $table->bigInteger('amount');
            $table->date('due_date')->nullable();
            $table->boolean('is_paid')->default(false);
            $table->date('paid_at')->nullable();
            $table->boolean('show_on_pdf')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('legacy_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // Dépenses réelles liées au devis (achats, sous-traitance, transport…)
        Schema::create('quote_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quote_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->text('description')->nullable();
            $table->decimal('amount', 15, 2);
            $table->date('spent_at');
            $table->boolean('is_paid')->default(true);
            $table->foreignId('document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('legacy_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // Bordereaux de livraison ou de travaux, sans prix
        Schema::create('delivery_notes', function (Blueprint $table) {
            $table->id();
            $table->string('number', 50)->unique();
            $table->foreignId('quote_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20)->default('delivery');
            $table->string('title');
            $table->date('delivered_at');
            $table->text('done')->nullable();
            $table->text('todo')->nullable();
            $table->boolean('show_logo')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('legacy_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('delivery_note_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_note_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->string('designation');
            $table->text('reference')->nullable();
            $table->string('unit', 20)->nullable();
            $table->decimal('quantity', 12, 2)->default(1);
            $table->timestamps();
        });

        // Historique des envois par e-mail
        Schema::create('quote_emails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quote_id')->constrained()->cascadeOnDelete();
            $table->json('to');
            $table->json('cc')->nullable();
            $table->string('subject');
            $table->text('message')->nullable();
            $table->json('attachments')->nullable();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_emails');
        Schema::dropIfExists('delivery_note_lines');
        Schema::dropIfExists('delivery_notes');
        Schema::dropIfExists('quote_expenses');
        Schema::dropIfExists('quote_deposits');
        Schema::table('quotes', fn (Blueprint $table) => $table->dropColumn('proposal_options'));
        Schema::table('quote_sections', fn (Blueprint $table) => $table->dropColumn('proposal'));
        Schema::dropIfExists('quote_template_lines');
        Schema::dropIfExists('quote_templates');
        Schema::dropIfExists('quote_systems');
    }
};
