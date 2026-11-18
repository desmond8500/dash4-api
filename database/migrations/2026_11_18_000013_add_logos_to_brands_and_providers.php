<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Logo des marques ; logo et position (GPS) des fournisseurs. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->string('logo_path')->nullable()->after('website');
        });
        Schema::table('providers', function (Blueprint $table) {
            $table->string('logo_path')->nullable()->after('website');
            $table->decimal('latitude', 10, 7)->nullable()->after('address');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
        });
    }

    public function down(): void
    {
        Schema::table('providers', fn (Blueprint $table) => $table->dropColumn(['logo_path', 'latitude', 'longitude']));
        Schema::table('brands', fn (Blueprint $table) => $table->dropColumn('logo_path'));
    }
};
