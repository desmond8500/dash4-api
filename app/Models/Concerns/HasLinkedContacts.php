<?php

namespace App\Models\Concerns;

use App\Models\RecordContact;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** Contacts liés à la fiche (projet, devis), dans l'ordre choisi ; les contacts supprimés n'apparaissent plus. */
trait HasLinkedContacts
{
    public function linkedContacts(): MorphMany
    {
        return $this->morphMany(RecordContact::class, 'contactable')
            ->whereHas('contact')
            ->orderBy('position')->orderBy('id');
    }
}
