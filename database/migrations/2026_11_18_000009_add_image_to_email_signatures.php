<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Image (photo, logo) sous le texte d'une signature d'e-mail, et sa largeur d'affichage. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_signatures', function (Blueprint $table) {
            $table->string('image_path')->nullable()->after('body');
            $table->unsignedSmallInteger('image_width')->default(160)->after('image_path');
        });
    }

    public function down(): void
    {
        Schema::table('email_signatures', fn (Blueprint $table) => $table->dropColumn(['image_path', 'image_width']));
    }
};
