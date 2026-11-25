<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Outils pratiques (calculs, conversions) liés à des marques, fournisseurs ou articles. Les outils eux-mêmes sont du code. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('practical_tool_links', function (Blueprint $table) {
            $table->id();
            $table->string('tool', 60);
            $table->morphs('linkable');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tool', 'linkable_type', 'linkable_id'], 'practical_tool_links_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('practical_tool_links');
    }
};
