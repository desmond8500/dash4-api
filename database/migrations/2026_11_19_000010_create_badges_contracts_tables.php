<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Badges (modèles par client, badges par projet) ; forfaits et contrats de maintenance.
 * Reprend `badges`, `badge_modeles`, `forfaits` de Dash3 ; les contrats sont conçus pour Dash4
 * (la table `contracts` de Dash3 est vide).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('badge_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('orientation', 20)->default('landscape');
            $table->string('primary_color', 20)->default('#1d4ed8');
            $table->string('text_color', 20)->default('#111827');
            $table->string('title')->nullable();
            $table->string('subtitle')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('background_path')->nullable();
            // Champs imprimés : job_title, department, division, employee_number, valid_until
            $table->json('fields')->nullable();
            $table->string('footer')->nullable();
            $table->unsignedBigInteger('legacy_id')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('badges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('badge_template_id')->nullable()->constrained()->nullOnDelete();
            $table->string('firstname')->nullable();
            $table->string('lastname')->nullable();
            $table->string('job_title')->nullable();
            $table->string('department')->nullable();
            $table->string('division')->nullable();
            $table->string('employee_number', 100)->nullable();
            $table->string('address')->nullable();
            $table->date('valid_until')->nullable();
            $table->string('photo_path')->nullable();
            $table->timestamp('printed_at')->nullable();
            $table->unsignedBigInteger('legacy_id')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('flat_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('article_id')->nullable()->constrained()->nullOnDelete();
            $table->string('designation');
            $table->text('description')->nullable();
            $table->string('unit', 20)->nullable();
            $table->decimal('price', 14, 2)->default(0);
            $table->unsignedBigInteger('legacy_id')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->string('number', 50)->nullable()->unique();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->string('status', 20)->default('draft');
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->boolean('tacit_renewal')->default(false);
            $table->string('billing_period', 20)->default('monthly');
            $table->date('next_invoice_date')->nullable();
            $table->unsignedSmallInteger('visits_per_year')->default(0);
            $table->unsignedSmallInteger('notice_days')->default(30);
            $table->text('description')->nullable();
            $table->text('terms')->nullable();
            $table->timestamp('end_notified_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('legacy_id')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('contract_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->foreignId('flat_rate_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->string('designation');
            $table->string('unit', 20)->nullable();
            $table->decimal('quantity', 12, 2)->default(1);
            // Prix pour une période de facturation
            $table->decimal('unit_price', 14, 2)->default(0);
            $table->timestamps();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('contract_id')->nullable()->after('quote_id')->constrained()->nullOnDelete();
            $table->date('period_start')->nullable()->after('contract_id');
            $table->date('period_end')->nullable()->after('period_start');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('contract_id');
            $table->dropColumn(['period_start', 'period_end']);
        });
        Schema::dropIfExists('contract_lines');
        Schema::dropIfExists('contracts');
        Schema::dropIfExists('flat_rates');
        Schema::dropIfExists('badges');
        Schema::dropIfExists('badge_templates');
    }
};
