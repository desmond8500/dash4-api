<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Vehicle;

/**
 * Parc automobile : `vehicles.view` voit tout le parc ; `vehicles.view.own` voit les véhicules dont il est
 * le conducteur et y note son kilométrage. Gestion avec `vehicles.create|update|delete`.
 */
class VehiclePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAny(['vehicles.view', 'vehicles.view.own']);
    }

    public function view(User $user, Vehicle $vehicle): bool
    {
        return $user->can('vehicles.view') || ($user->can('vehicles.view.own') && $vehicle->driver_id === $user->id);
    }

    public function create(User $user): bool
    {
        return $user->can('vehicles.create');
    }

    public function update(User $user, Vehicle $vehicle): bool
    {
        return $user->can('vehicles.update');
    }

    public function delete(User $user, Vehicle $vehicle): bool
    {
        return $user->can('vehicles.delete');
    }

    /** Relever le compteur : qui gère le parc, ou le conducteur attitré. */
    public function recordMileage(User $user, Vehicle $vehicle): bool
    {
        return $this->update($user, $vehicle) || ($vehicle->driver_id === $user->id && $this->view($user, $vehicle));
    }
}
