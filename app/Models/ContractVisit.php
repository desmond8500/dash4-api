<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Visite de maintenance prévue par un contrat ; sa tâche apparaît au planning. */
#[Fillable(['number', 'planned_on'])]
class ContractVisit extends Model
{
    protected function casts(): array
    {
        return ['planned_on' => 'date', 'number' => 'integer'];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class)->withTrashed();
    }
}
