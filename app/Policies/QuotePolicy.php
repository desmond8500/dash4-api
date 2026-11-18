<?php

namespace App\Policies;

use App\Models\Quote;
use App\Models\User;

class QuotePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAny(['quotes.view', 'quotes.view.own']);
    }

    public function view(User $user, Quote $quote): bool
    {
        return Quote::query()->visibleTo($user)->whereKey($quote->getKey())->withTrashed()->exists();
    }

    public function create(User $user): bool
    {
        return $user->can('quotes.create');
    }

    /** `quotes.update` : tous ; `quotes.update.own` (chef de projet) : ses devis. */
    public function update(User $user, Quote $quote): bool
    {
        return $user->can('quotes.update') || ($user->can('quotes.update.own') && $quote->isOwnedBy($user));
    }

    /** Accepter ou refuser un devis engage l'entreprise. */
    public function validate(User $user, Quote $quote): bool
    {
        return $user->can('quotes.validate') && $this->update($user, $quote);
    }

    public function delete(User $user, Quote $quote): bool
    {
        return $user->can('quotes.delete');
    }

    public function export(User $user, Quote $quote): bool
    {
        return $this->view($user, $quote) && $user->can('prices.view');
    }
}
