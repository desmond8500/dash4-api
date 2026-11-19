<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** E-mail reçu sur l'adresse des demandes : nouveau, transformé en demande, ou ignoré. */
class InboundEmail extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['received_at' => 'datetime', 'handled_at' => 'datetime', 'attachments' => 'array'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function clientRequest(): BelongsTo
    {
        return $this->belongsTo(ClientRequest::class)->withTrashed();
    }

    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }
}
