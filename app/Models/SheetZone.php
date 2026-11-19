<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['position', 'number', 'name', 'code', 'equipment_label', 'equipment_id', 'room_id'])]
class SheetZone extends Model
{
    protected $touches = ['sheet'];

    public function sheet(): BelongsTo
    {
        return $this->belongsTo(Sheet::class)->withTrashed();
    }
}
