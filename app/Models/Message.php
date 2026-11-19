<?php

namespace App\Models;

use App\Models\Concerns\HasAttachments;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Message d'une conversation ; fichiers et photos en documents joints. */
#[Fillable(['client_uuid', 'body'])]
class Message extends Model
{
    use HasAttachments, SoftDeletes;

    protected $touches = ['conversation'];

    protected function casts(): array
    {
        return ['edited_at' => 'datetime'];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
