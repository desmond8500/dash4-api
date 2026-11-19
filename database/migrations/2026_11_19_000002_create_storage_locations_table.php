<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Emplacements du magasin (étagère, rayon, armoire, véhicule…). Les emplacements saisis en texte
 * sur les articles deviennent des emplacements ; l'article garde le lien.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storage_locations', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('code', 50)->nullable()->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->index('updated_at');
        });

        Schema::table('articles', function (Blueprint $table) {
            $table->foreignId('storage_location_id')->nullable()->after('provider_id')->constrained()->nullOnDelete();
        });

        DB::table('articles')->whereNotNull('location')->where('location', '!=', '')->orderBy('id')->each(function ($a) {
            $name = trim($a->location);
            $id = DB::table('storage_locations')->where('name', $name)->value('id')
                ?? DB::table('storage_locations')->insertGetId(['name' => $name, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('articles')->where('id', $a->id)->update(['storage_location_id' => $id]);
        });

        Schema::table('articles', fn (Blueprint $table) => $table->dropColumn('location'));
    }

    public function down(): void
    {
        Schema::table('articles', fn (Blueprint $table) => $table->string('location')->nullable());
        DB::table('articles')->whereNotNull('storage_location_id')->orderBy('id')->each(fn ($a) => DB::table('articles')
            ->where('id', $a->id)->update(['location' => DB::table('storage_locations')->where('id', $a->storage_location_id)->value('name')]));
        Schema::table('articles', fn (Blueprint $table) => $table->dropConstrainedForeignId('storage_location_id'));
        Schema::dropIfExists('storage_locations');
    }
};
