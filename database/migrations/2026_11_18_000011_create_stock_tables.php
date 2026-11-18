<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stock (V1, un seul magasin) : marques, fournisseurs, articles (coût + coefficient),
 * articles liés, mouvements. Les bordereaux livrés sortent leurs articles du stock.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->text('description')->nullable();
            $table->string('website')->nullable();
            $table->unsignedBigInteger('legacy_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index('updated_at');
        });

        Schema::create('providers', function (Blueprint $table) {
            $table->id();
            $table->uuid('client_uuid')->nullable()->unique();
            $table->string('name');
            $table->string('contact_name')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->string('website')->nullable();
            $table->text('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('legacy_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index('name');
            $table->index('updated_at');
        });

        Schema::create('articles', function (Blueprint $table) {
            $table->id();
            $table->uuid('client_uuid')->nullable()->unique();
            $table->string('designation');
            $table->string('reference', 100)->nullable()->index();
            $table->string('barcode', 100)->nullable()->index();
            $table->text('description')->nullable();
            $table->string('category', 20)->default('other');
            $table->string('unit', 20)->nullable();
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('provider_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('unit_cost', 15, 2)->default(0);
            $table->decimal('coefficient', 8, 4)->default(1);
            $table->decimal('quantity', 12, 2)->default(0);
            $table->decimal('min_quantity', 12, 2)->nullable();
            $table->string('location')->nullable();
            $table->decimal('weight', 10, 3)->nullable();
            $table->string('image_path')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('legacy_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index('designation');
            $table->index('updated_at');
        });

        // Articles à prévoir avec un article (Dash3 : article_dependences)
        Schema::create('article_dependencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dependency_id')->constrained('articles')->cascadeOnDelete();
            $table->decimal('quantity', 12, 2)->default(1);
            $table->timestamps();
            $table->unique(['article_id', 'dependency_id']);
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->uuid('client_uuid')->nullable()->unique();
            $table->foreignId('article_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            // Positif : entrée ; négatif : sortie
            $table->decimal('quantity', 12, 2);
            $table->decimal('quantity_after', 12, 2);
            $table->decimal('unit_cost', 15, 2)->nullable();
            $table->string('reason')->nullable();
            $table->nullableMorphs('source');
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('moved_at');
            $table->timestamps();
            $table->index(['article_id', 'moved_at']);
            $table->index('moved_at');
        });

        Schema::table('delivery_notes', function (Blueprint $table) {
            $table->timestamp('stock_released_at')->nullable()->after('show_logo');
            $table->foreignId('stock_released_by')->nullable()->after('stock_released_at')->constrained('users')->nullOnDelete();
        });

        Schema::table('delivery_note_lines', function (Blueprint $table) {
            $table->foreignId('article_id')->nullable()->after('position')->constrained()->nullOnDelete();
        });

    }

    public function down(): void
    {
        Schema::table('delivery_note_lines', fn (Blueprint $table) => $table->dropConstrainedForeignId('article_id'));
        Schema::table('delivery_notes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('stock_released_by');
            $table->dropColumn('stock_released_at');
        });
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('article_dependencies');
        Schema::dropIfExists('articles');
        Schema::dropIfExists('providers');
        Schema::dropIfExists('brands');
    }
};
