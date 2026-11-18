<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Comptabilité (V1) : catégories, caisses (espaces de dépenses avec membres, versements et solde),
 * dépenses (soumise → validée ou refusée → remboursée), transactions saisies à la main.
 * Les dépenses réelles des devis (quote_expenses) sont reprises dans `expenses`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('color', 20)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('legacy_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('expense_spaces', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('legacy_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index('updated_at');
        });

        Schema::create('expense_space_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_space_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['expense_space_id', 'user_id']);
        });

        // Versements qui alimentent une caisse
        Schema::create('expense_space_fundings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_space_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('amount');
            $table->date('funded_at');
            $table->string('method', 20)->nullable();
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->uuid('client_uuid')->nullable()->unique();
            $table->string('label');
            $table->text('description')->nullable();
            $table->bigInteger('amount');
            $table->date('spent_at');
            $table->foreignId('expense_category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('expense_space_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('quote_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('provider_id')->nullable()->constrained()->nullOnDelete();
            $table->string('payment_method', 20)->nullable();
            // Avancée par la personne : à lui rembourser une fois validée
            $table->boolean('advanced')->default(false);
            // Réglée au fournisseur (sinon : à payer)
            $table->boolean('is_paid')->default(true);
            $table->string('status', 20)->default('submitted');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('rejection_reason')->nullable();
            $table->foreignId('reimbursed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reimbursed_at')->nullable();
            $table->foreignId('document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('legacy_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status', 'spent_at']);
            $table->index('spent_at');
            $table->index('updated_at');
        });

        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->uuid('client_uuid')->nullable()->unique();
            $table->string('direction', 3);
            $table->string('label');
            $table->bigInteger('amount');
            $table->date('transacted_at');
            $table->foreignId('expense_category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('provider_id')->nullable()->constrained()->nullOnDelete();
            $table->string('method', 20)->nullable();
            $table->string('reference')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('legacy_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index('transacted_at');
        });

        // Reprise des dépenses réelles des devis
        if (Schema::hasTable('quote_expenses')) {
            DB::table('quote_expenses')->orderBy('id')->each(function ($e) {
                DB::table('expenses')->insert([
                    'label' => $e->label,
                    'description' => $e->description,
                    'amount' => (int) round((float) $e->amount),
                    'spent_at' => $e->spent_at,
                    'project_id' => DB::table('quotes')->where('id', $e->quote_id)->value('project_id'),
                    'quote_id' => $e->quote_id,
                    'is_paid' => $e->is_paid,
                    'status' => 'approved',
                    'decided_at' => $e->created_at,
                    'document_id' => $e->document_id,
                    'created_by' => $e->created_by,
                    'legacy_id' => $e->legacy_id,
                    'created_at' => $e->created_at,
                    'updated_at' => $e->updated_at,
                    'deleted_at' => $e->deleted_at,
                ]);
            });
            Schema::drop('quote_expenses');
        }

        foreach (['Matériel', 'Transport', 'Carburant', 'Restauration', 'Hébergement', 'Outillage', 'Sous-traitance', 'Télécom', 'Divers'] as $name) {
            DB::table('expense_categories')->insert(['name' => $name, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
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
        DB::table('expenses')->whereNotNull('quote_id')->orderBy('id')->each(fn ($e) => DB::table('quote_expenses')->insert([
            'quote_id' => $e->quote_id, 'label' => $e->label, 'description' => $e->description, 'amount' => $e->amount,
            'spent_at' => $e->spent_at, 'is_paid' => $e->is_paid, 'document_id' => $e->document_id, 'created_by' => $e->created_by,
            'legacy_id' => $e->legacy_id, 'created_at' => $e->created_at, 'updated_at' => $e->updated_at, 'deleted_at' => $e->deleted_at,
        ]));
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('expense_space_fundings');
        Schema::dropIfExists('expense_space_members');
        Schema::dropIfExists('expense_spaces');
        Schema::dropIfExists('expense_categories');
    }
};
