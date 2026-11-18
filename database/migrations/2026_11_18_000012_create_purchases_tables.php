<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Achats (Dash3 : achats, achat_rows) : commande fournisseur numérotée BC-2026-0001,
 * réceptions totales ou partielles qui font entrer les articles en stock.
 * La facture fournisseur se joint en document.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->uuid('client_uuid')->nullable()->unique();
            $table->string('number', 50)->unique();
            $table->foreignId('provider_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title')->nullable();
            $table->string('status', 20)->default('draft');
            $table->date('ordered_at')->nullable();
            $table->date('expected_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->string('tax_mode', 10)->default('none');
            $table->decimal('vat_rate', 5, 2)->default(18);
            $table->bigInteger('subtotal')->default(0);
            $table->bigInteger('tax_amount')->default(0);
            $table->bigInteger('total')->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('legacy_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status', 'expected_at']);
            $table->index('updated_at');
        });

        Schema::create('purchase_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->foreignId('article_id')->nullable()->constrained()->nullOnDelete();
            $table->string('designation');
            $table->text('reference')->nullable();
            $table->string('unit', 20)->nullable();
            $table->decimal('quantity', 12, 2)->default(1);
            $table->decimal('received_quantity', 12, 2)->default(0);
            $table->decimal('unit_cost', 15, 2)->default(0);
            $table->bigInteger('line_total')->default(0);
            $table->timestamps();
        });

        Schema::create('purchase_receptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->constrained()->cascadeOnDelete();
            $table->timestamp('received_at');
            $table->json('lines');
            $table->text('note')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_receptions');
        Schema::dropIfExists('purchase_lines');
        Schema::dropIfExists('purchases');
    }
};
