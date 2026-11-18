<?php

namespace App\Policies;

use App\Models\Purchase;
use App\Models\User;

/**
 * Achats. Le chef de projet (purchases.create + view.own) prépare un brouillon ; le comptable
 * (purchases.update) chiffre et commande ; le magasinier (purchases.receive) réceptionne.
 */
class PurchasePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAny(['purchases.view', 'purchases.view.own']);
    }

    public function view(User $user, Purchase $purchase): bool
    {
        return $user->can('purchases.view') || ($user->can('purchases.view.own') && $purchase->created_by === $user->id);
    }

    public function create(User $user): bool
    {
        return $user->can('purchases.create');
    }

    /** Modifier : purchases.update, ou l'auteur de son propre brouillon. */
    public function update(User $user, Purchase $purchase): bool
    {
        return $user->can('purchases.update')
            || ($purchase->isDraft() && $purchase->created_by === $user->id && $user->can('purchases.create'));
    }

    /** Commander ou annuler. */
    public function order(User $user, Purchase $purchase): bool
    {
        return $user->can('purchases.update');
    }

    public function receive(User $user, Purchase $purchase): bool
    {
        return $user->can('purchases.receive');
    }

    public function delete(User $user, Purchase $purchase): bool
    {
        return $purchase->isDraft() && ($user->can('purchases.delete') || $purchase->created_by === $user->id);
    }
}
