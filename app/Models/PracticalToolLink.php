<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** Lien entre un outil pratique (identifié par son code, ex. `galaxy-dimension-text`) et une marque, un fournisseur ou un article. */
#[Fillable(['tool'])]
class PracticalToolLink extends Model
{
    public function linkable(): MorphTo
    {
        return $this->morphTo();
    }
}
