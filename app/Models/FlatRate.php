<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Forfait (maintenance, intervention, abonnement…), général ou propre à un client. */
#[Fillable(['client_id', 'article_id', 'designation', 'description', 'unit', 'price'])]
class FlatRate extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return ['price' => 'float'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class)->withTrashed();
    }
}
