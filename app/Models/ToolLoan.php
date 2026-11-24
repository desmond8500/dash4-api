<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Prêt d'un outil à un utilisateur (ou à une personne extérieure), avec date de retour prévue et rappels. */
#[Fillable(['client_uuid', 'borrower_id', 'borrower_name', 'borrower_phone', 'project_id', 'lent_at', 'due_on', 'notes'])]
class ToolLoan extends Model
{
    protected $attributes = ['reminder_level' => 0];

    protected function casts(): array
    {
        return [
            'lent_at' => 'datetime',
            'due_on' => 'date',
            'returned_at' => 'datetime',
            'last_reminded_on' => 'date',
            'reminder_level' => 'integer',
        ];
    }

    public function tool(): BelongsTo
    {
        return $this->belongsTo(Tool::class)->withTrashed();
    }

    public function borrower(): BelongsTo
    {
        return $this->belongsTo(User::class, 'borrower_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withTrashed();
    }

    public function lender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function returner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'returned_by');
    }

    /** Nom affiché de l'emprunteur. */
    public function borrowerLabel(): string
    {
        return $this->borrower?->full_name ?? $this->borrower_name ?? 'Emprunteur inconnu';
    }
}
