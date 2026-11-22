<?php

namespace App\Models;

use App\Enums\EquipmentStatus;
use App\Models\Concerns\HasAttachments;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Équipement d'un système installé ; `parent_id` : équipement secondaire (disque d'un enregistreur…).
 * `characteristics` : couples libellé / valeur (adresse IP, ports, identifiants…).
 */
#[Fillable([
    'client_uuid', 'parent_id', 'article_id', 'quote_line_id', 'level_id', 'room_id', 'name', 'type', 'model', 'serial_number',
    'quantity', 'status', 'characteristics', 'description', 'position',
])]
class Equipment extends Model
{
    use HasAttachments, SoftDeletes;

    protected $table = 'equipment';

    protected $attributes = ['status' => 'planned', 'quantity' => 1];

    protected $touches = ['installedSystem'];

    protected function casts(): array
    {
        return ['status' => EquipmentStatus::class, 'characteristics' => 'array', 'quantity' => 'integer'];
    }

    public function installedSystem(): BelongsTo
    {
        return $this->belongsTo(InstalledSystem::class)->withTrashed();
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class)->withTrashed();
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(Level::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }
}
