<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable(['label', 'address'])]
class Email extends Model
{
    public function emailable(): MorphTo
    {
        return $this->morphTo();
    }
}
