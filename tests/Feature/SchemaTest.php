<?php

use Illuminate\Support\Facades\Schema;

it('garde des noms d\'index et de clés de 64 caractères au plus (limite de MariaDB en production)', function () {
    $tooLong = collect(Schema::getTableListing(schemaQualified: false))
        ->flatMap(fn (string $table) => collect(Schema::getIndexes($table))->pluck('name')
            ->concat(collect(Schema::getForeignKeys($table))->pluck('name'))
            ->filter(fn ($name) => $name && mb_strlen($name) > 64)
            ->map(fn ($name) => "{$table} : {$name}"))
        ->values()->all();

    expect($tooLong)->toBe([]);
});
