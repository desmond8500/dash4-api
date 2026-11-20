<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Avoirs sur facture (AV-…) et relances de paiement. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_notes', function (Blueprint $table) {
            $table->id();
            $table->string('number', 50)->unique();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->date('issued_at');
            $table->text('reason');
            // Montant de l'avoir, comme le total de la facture (taxe comprise)
            $table->bigInteger('amount');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('updated_at');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->bigInteger('credited_amount')->default(0)->after('paid_amount');
            // Dernier palier de relance signalé (pour ne prévenir qu'une fois par palier)
            $table->unsignedTinyInteger('reminder_level')->default(0)->after('credited_amount');
        });

        Schema::create('invoice_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('level');
            $table->boolean('automatic')->default(false);
            $table->foreignId('quote_email_id')->nullable()->constrained('quote_emails')->nullOnDelete();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_reminders');
        Schema::table('invoices', fn (Blueprint $table) => $table->dropColumn(['credited_amount', 'reminder_level']));
        Schema::dropIfExists('credit_notes');
    }
};
