<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;

class InvoicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('invoices.view') && ! $user->isTechnician();
    }

    public function view(User $user, Invoice $invoice): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->can('invoices.create');
    }

    /** Modifier un brouillon, enregistrer un paiement, joindre un document. */
    public function update(User $user, Invoice $invoice): bool
    {
        return $user->can('invoices.update');
    }

    /** Émettre (numéro attribué, facture verrouillée) ou annuler une facture émise. */
    public function issue(User $user, Invoice $invoice): bool
    {
        return $user->can('invoices.issue');
    }

    /** Seul un brouillon se supprime : une facture émise s'annule. */
    public function delete(User $user, Invoice $invoice): bool
    {
        return $user->can('invoices.delete') && $invoice->isDraft();
    }
}
