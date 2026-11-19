<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['title', 'subtitle', 'place', 'start_date', 'end_date', 'current', 'description', 'level', 'position'])]
class CvItem extends Model
{
    protected function casts(): array
    {
        return ['current' => 'boolean', 'level' => 'integer'];
    }
}
