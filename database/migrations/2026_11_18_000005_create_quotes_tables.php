<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Devis (Dash3 : invoices, invoice_sections, invoice_rows).
 * Prix d'une ligne = coût × coefficient (comme Dash3) ; montants en francs CFA.
 * Totaux recalculés et gardés sur le devis à chaque modification d'une ligne.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotes', function (Blueprint $table) {
            $table->id();
            $table->uuid('client_uuid')->nullable()->unique();
            $table->string('number', 50)->unique();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->text('introduction')->nullable();
            $table->text('payment_terms')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('draft');
            $table->date('issued_at');
            $table->date('valid_until')->nullable();
            // Taxe (TVA ajoutée, BRS déduite, ou aucune) et taux figés à la création
            $table->string('tax_mode', 10)->default('vat');
            $table->decimal('vat_rate', 5, 2)->default(18);
            $table->decimal('brs_rate', 5, 2)->default(5);
            $table->decimal('discount_rate', 5, 2)->default(0);
            $table->foreignId('bank_account_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('show_signature')->default(true);
            // Totaux (recalculés)
            $table->bigInteger('subtotal')->default(0);
            $table->bigInteger('discount_amount')->default(0);
            $table->bigInteger('tax_amount')->default(0);
            $table->bigInteger('total')->default(0);
            $table->decimal('cost_total', 15, 2)->default(0);
            // Suivi
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('signed_at')->nullable();
            $table->foreignId('signed_document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->boolean('is_favorite')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('legacy_id')->nullable()->unique();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'issued_at']);
            $table->index('updated_at');
        });

        Schema::create('quote_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quote_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->unsignedInteger('position')->default(0);
            // Section masquée : ni imprimée ni comptée (Dash3 : show)
            $table->boolean('is_visible')->default(true);
            $table->unsignedBigInteger('legacy_id')->nullable();
            $table->timestamps();

            $table->index(['quote_id', 'position']);
        });

        Schema::create('quote_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quote_section_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            // Lien vers l'article du stock, quand le module existera
            $table->unsignedBigInteger('article_id')->nullable()->index();
            $table->string('designation');
            $table->text('reference')->nullable();
            $table->text('comment')->nullable();
            $table->string('category', 20)->default('other');
            $table->string('unit', 20)->nullable();
            $table->decimal('quantity', 12, 2)->default(1);
            $table->decimal('unit_cost', 15, 2)->default(0);
            $table->decimal('coefficient', 8, 4)->default(1);
            $table->bigInteger('unit_price')->default(0);
            $table->bigInteger('line_total')->default(0);
            $table->unsignedBigInteger('legacy_id')->nullable();
            $table->timestamps();

            $table->index(['quote_section_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_lines');
        Schema::dropIfExists('quote_sections');
        Schema::dropIfExists('quotes');
    }
};
