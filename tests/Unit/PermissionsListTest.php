<?php

use App\Support\Permissions;

it('n\'a pas de permission en double', function () {
    $all = Permissions::all();

    expect($all)->toHaveCount(count(array_unique($all)));
});

it('ne donne aux profils que des permissions qui existent', function () {
    $all = Permissions::all();

    foreach (Permissions::roles() as $role => $permissions) {
        expect(array_diff($permissions, $all))->toBe([], "Permission inconnue dans le profil {$role}");
    }
});

it('ne met aucune permission interdite dans le profil Technicien', function () {
    $technician = Permissions::roles()[Permissions::ROLE_TECHNICIAN];

    expect(array_intersect($technician, Permissions::FORBIDDEN_FOR_TECHNICIANS))->toBe([]);
});
