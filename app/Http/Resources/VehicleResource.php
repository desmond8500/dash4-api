<?php

namespace App\Http\Resources;

use App\Support\Fleet;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Vehicle — prix d'achat, coûts des papiers et des entretiens seulement avec `costs.view`. */
class VehicleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $costs = (bool) $user?->can('costs.view');
        $today = today();
        $person = fn ($u) => $u ? ['id' => $u->id, 'full_name' => $u->full_name] : null;

        return [
            'id' => $this->id,
            'kind' => Fleet::label(Fleet::KINDS, $this->kind),
            'name' => $this->name,
            'brand' => $this->brand,
            'model' => $this->model,
            'plate' => $this->plate,
            'vin' => $this->vin,
            'year' => $this->year,
            'color' => $this->color,
            'fuel' => Fleet::label(Fleet::FUELS, $this->fuel),
            'fiscal_power' => $this->fiscal_power,
            'seats' => $this->seats,
            'status' => Fleet::label(Fleet::STATUSES, $this->status),
            'driver_id' => $this->driver_id,
            'driver' => $this->whenLoaded('driver', fn () => $person($this->driver)),
            'acquired_on' => $this->acquired_on?->toDateString(),
            'purchase_price' => $this->when($costs, $this->purchase_price),
            'photo_url' => $this->resource->photoUrl(),
            'mileage' => $this->mileage,
            'service_interval_km' => $this->service_interval_km,
            'service_interval_months' => $this->service_interval_months,
            'characteristics' => $this->characteristics ?? [],
            'notes' => $this->notes,
            'alerts' => $this->when($this->relationLoaded('papers') && $this->relationLoaded('maintenances'), fn () => Fleet::alerts($this->resource, $today)),
            'papers' => $this->whenLoaded('papers', fn () => $this->papers->map(fn ($p) => [
                'id' => $p->id,
                'type' => Fleet::label(Fleet::PAPER_TYPES, $p->type),
                'label' => $p->label,
                'title' => Fleet::paperLabel($p),
                'number' => $p->number,
                'provider' => $p->provider,
                'issued_on' => $p->issued_on?->toDateString(),
                'expires_on' => $p->expires_on?->toDateString(),
                'state' => Fleet::paperState($p, $today),
                'current' => in_array($p->id, array_map(fn ($c) => $c->id, Fleet::currentPapers($this->papers)), true),
                'notes' => $p->notes,
                'documents_count' => $p->documents_count ?? null,
                ...($costs ? ['cost' => $p->cost] : []),
            ])->values()),
            'mileages' => $this->whenLoaded('mileages', fn () => $this->mileages->map(fn ($m) => [
                'id' => $m->id,
                'read_on' => $m->read_on->toDateString(),
                'km' => $m->km,
                'note' => $m->note,
                'creator' => $person($m->creator),
            ])->values()),
            'maintenances' => $this->whenLoaded('maintenances', fn () => $this->maintenances->map(fn ($m) => [
                'id' => $m->id,
                'type' => Fleet::label(Fleet::MAINTENANCE_TYPES, $m->type),
                'done_on' => $m->done_on->toDateString(),
                'km' => $m->km,
                'description' => $m->description,
                'garage' => $m->garage,
                'next_on' => $m->next_on?->toDateString(),
                'next_km' => $m->next_km,
                'expense_id' => $m->expense_id,
                ...($costs ? ['cost' => $m->cost] : []),
            ])->values()),
            'upcoming' => $this->whenLoaded('maintenances', fn () => Fleet::upcoming($this->resource, $today)),
            'costs' => $this->when($costs && $this->relationLoaded('papers') && $this->relationLoaded('maintenances'), fn () => $this->costSummary()),
            'can' => [
                'update' => (bool) $user?->can('update', $this->resource),
                'delete' => (bool) $user?->can('delete', $this->resource),
                'record_mileage' => (bool) $user?->can('recordMileage', $this->resource),
                'add_photos' => (bool) $user?->can('addPhotos', $this->resource),
            ],
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /** Coûts : papiers, entretiens non passés en dépense, dépenses liées (comptées), total et cette année. */
    private function costSummary(): array
    {
        $year = (string) today()->year;
        $expenses = $this->expenses()->whereIn('status', \App\Enums\ExpenseStatus::counted())->get(['amount', 'spent_at']);
        $maint = $this->maintenances->whereNull('expense_id');
        $papers = (int) $this->papers->sum('cost');
        $maintenance = (int) $maint->sum('cost');
        $spent = (int) $expenses->sum('amount');

        return [
            'papers' => $papers,
            'maintenance' => $maintenance,
            'expenses' => $spent,
            'total' => $papers + $maintenance + $spent,
            'this_year' => (int) $this->papers->filter(fn ($p) => ($p->issued_on ?? $p->created_at)?->format('Y') === $year)->sum('cost')
                + (int) $maint->filter(fn ($m) => $m->done_on->format('Y') === $year)->sum('cost')
                + (int) $expenses->filter(fn ($e) => $e->spent_at?->format('Y') === $year)->sum('amount'),
        ];
    }
}
