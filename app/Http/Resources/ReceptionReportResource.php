<?php

namespace App\Http\Resources;

use App\Models\ReceptionReport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/** @mixin \App\Models\ReceptionReport — le devis lié n'est montré qu'à qui voit les devis. */
class ReceptionReportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $quotes = (bool) $user?->canAny(['quotes.view', 'quotes.view.own']);

        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'project' => $this->whenLoaded('project', fn () => ['id' => $this->project->id, 'name' => $this->project->name]),
            'quote_id' => $quotes ? $this->quote_id : null,
            'quote' => $this->when($quotes && $this->relationLoaded('quote'), fn () => $this->quote ? ['id' => $this->quote->id, 'number' => $this->quote->number, 'title' => $this->quote->title] : null),
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'date' => $this->date?->toDateString(),
            'status' => ['value' => $this->status->value, 'label' => $this->status->label()],
            'location' => $this->location,
            'parties' => collect(ReceptionReport::PARTIES)->map(fn ($label, $key) => [
                'key' => $key,
                'label' => $label,
                'name' => $this->{"{$key}_name"},
                'logo_url' => $this->{"{$key}_logo_path"} ? Storage::disk('public')->url($this->{"{$key}_logo_path"}) : null,
            ])->values(),
            'observations' => $this->observations,
            'reserves' => array_values(array_map(fn ($r) => ['text' => (string) ($r['text'] ?? ''), 'lifted_at' => $r['lifted_at'] ?? null], $this->reserves ?? [])),
            'systems' => $this->whenLoaded('systems', fn () => $this->systems->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'progress' => $s->progress])->values()),
            'creator' => $this->whenLoaded('creator', fn () => AttachmentResources::author($this->creator)),
            'can' => [
                'update' => (bool) $user?->can('update', $this->resource),
                'delete' => (bool) $user?->can('delete', $this->resource),
            ],
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
