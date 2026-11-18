<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Modèle de devis : un ensemble de lignes prêtes à insérer dans une section. */
#[Fillable(['quote_system_id', 'name', 'description', 'icon'])]
class QuoteTemplate extends Model
{
    use SoftDeletes;

    public function system(): BelongsTo
    {
        return $this->belongsTo(QuoteSystem::class, 'quote_system_id')->withTrashed();
    }

    public function lines(): HasMany
    {
        return $this->hasMany(QuoteTemplateLine::class)->orderBy('position')->orderBy('id');
    }
}
