<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['position', 'designation', 'reference', 'unit', 'quantity'])]
class DeliveryNoteLine extends Model
{
    protected $attributes = ['position' => 0, 'quantity' => 1];

    protected function casts(): array
    {
        return ['quantity' => 'float'];
    }
}
