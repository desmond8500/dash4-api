<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Décision du 18/11 : les nouveaux devis sont « Sans taxe » par défaut.
 * Remplace aussi une valeur déjà enregistrée dans les paramètres.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->updateOrInsert(
            ['key' => 'default_tax'],
            ['value' => json_encode('none'), 'created_at' => now(), 'updated_at' => now()],
        );
        Cache::forget('dash4.settings');
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'default_tax')->delete();
        Cache::forget('dash4.settings');
    }
};
