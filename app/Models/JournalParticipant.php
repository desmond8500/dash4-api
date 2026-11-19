<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Intervenant : membre de l'équipe (user_id), contact du client (contact_id) ou simple nom. */
#[Fillable(['user_id', 'contact_id', 'name', 'role'])]
class JournalParticipant extends Model
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class)->withTrashed();
    }

    public function displayName(): string
    {
        return $this->user?->full_name ?? $this->contact?->full_name ?? $this->name ?? '—';
    }
}
