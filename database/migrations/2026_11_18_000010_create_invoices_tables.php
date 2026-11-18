<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Factures (Dash3 : factures, et invoices.facture / paydate / emitdate) et paiements.
 * Plusieurs factures par devis : acompte (% sur tout, le matériel, le matériel + accessoires ou
 * la main-d'œuvre), facture complète, reliquat. Numéro FAC-2026-0001 attribué à l'émission.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->uuid('client_uuid')->nullable()->unique();
            // Vide tant que la facture est un brouillon
            $table->string('number', 50)->nullable()->unique();
            $table->foreignId('quote_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('quote_deposit_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            // all, equipment, equipment_accessories, labor, remainder, custom
            $table->string('scope', 30)->default('all');
            $table->decimal('percent', 5, 2)->default(100);
            $table->string('status', 20)->default('draft');
            $table->date('issued_at')->nullable();
            $table->date('due_at')->nullable();
            $table->text('introduction')->nullable();
            $table->text('payment_terms')->nullable();
            $table->text('notes')->nullable();
            $table->string('tax_mode', 10)->default('none');
            $table->decimal('vat_rate', 5, 2)->default(18);
            $table->decimal('brs_rate', 5, 2)->default(5);
            $table->foreignId('bank_account_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('show_signature')->default(true);
            $table->bigInteger('subtotal')->default(0);
            $table->bigInteger('tax_amount')->default(0);
            $table->bigInteger('total')->default(0);
            $table->bigInteger('paid_amount')->default(0);
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('legacy_id')->nullable()->unique();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'due_at']);
            $table->index('updated_at');
        });

        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->string('designation');
            $table->text('reference')->nullable();
            $table->string('unit', 20)->nullable();
            $table->decimal('quantity', 12, 2)->default(1);
            // Négatif pour une remise ou une déduction (« Déjà facturé »)
            $table->bigInteger('unit_price')->default(0);
            $table->bigInteger('line_total')->default(0);
            $table->timestamps();
        });

        Schema::create('invoice_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('amount');
            $table->date('paid_at');
            $table->string('method', 20);
            $table->string('reference')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        // Historique des e-mails : devis ou facture
        Schema::table('quote_emails', function (Blueprint $table) {
            $table->foreignId('quote_id')->nullable()->change();
            $table->foreignId('invoice_id')->nullable()->after('quote_id')->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('quote_emails', fn (Blueprint $table) => $table->dropConstrainedForeignId('invoice_id'));
        Schema::dropIfExists('invoice_payments');
        Schema::dropIfExists('invoice_lines');
        Schema::dropIfExists('invoices');
    }
};
