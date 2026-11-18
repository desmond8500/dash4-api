<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Système (vidéosurveillance, alarme, contrôle d'accès…) qui regroupe des modèles de devis. */
#[Fillable(['name', 'description', 'icon'])]
class QuoteSystem extends Model
{
    use SoftDeletes;

    public function templates(): HasMany
    {
        return $this->hasMany(QuoteTemplate::class)->orderBy('name');
    }
}
