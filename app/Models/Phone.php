<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable(['label', 'number'])]
class Phone extends Model
{
    public function phoneable(): MorphTo
    {
        return $this->morphTo();
    }
}
