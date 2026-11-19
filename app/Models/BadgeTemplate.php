<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Modèle de badge (souvent propre à un client) : couleurs, logo, fond, champs imprimés.
 * Format carte CR80 (85,6 × 54 mm), en paysage ou en portrait.
 */
#[Fillable(['client_id', 'name', 'orientation', 'primary_color', 'text_color', 'title', 'subtitle', 'fields', 'footer'])]
class BadgeTemplate extends Model
{
    use SoftDeletes;

    public const FIELDS = [
        'job_title' => 'Fonction',
        'department' => 'Service',
        'division' => 'Direction',
        'employee_number' => 'Matricule',
        'valid_until' => "Valable jusqu'au",
    ];

    protected $attributes = ['orientation' => 'landscape', 'primary_color' => '#1d4ed8', 'text_color' => '#111827'];

    protected function casts(): array
    {
        return ['fields' => 'array'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    /** @return list<string> */
    public function printedFields(): array
    {
        return array_values(array_intersect($this->fields ?? ['job_title', 'department'], array_keys(self::FIELDS)));
    }
}
