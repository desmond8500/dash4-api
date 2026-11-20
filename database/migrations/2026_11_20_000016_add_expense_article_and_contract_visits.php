<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Article acheté sur une dépense ; visites de maintenance des contrats, chacune liée à sa tâche du planning. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->foreignId('article_id')->nullable()->after('provider_id')->constrained()->nullOnDelete();
        });

        Schema::create('contract_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('number');
            $table->date('planned_on');
            $table->foreignId('task_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->unique(['contract_id', 'planned_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_visits');
        Schema::table('expenses', fn (Blueprint $table) => $table->dropConstrainedForeignId('article_id'));
    }
};
