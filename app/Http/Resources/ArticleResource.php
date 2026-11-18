<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/** @mixin \App\Models\Article — prix de vente avec prices.view, coût et coefficient avec costs.view. */
class ArticleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'client_uuid' => $this->client_uuid,
            'designation' => $this->designation,
            'reference' => $this->reference,
            'barcode' => $this->barcode,
            'description' => $this->description,
            'category' => ['value' => $this->category->value, 'label' => $this->category->label()],
            'unit' => $this->unit,
            'brand_id' => $this->brand_id,
            'brand' => $this->whenLoaded('brand', fn () => $this->brand ? ['id' => $this->brand->id, 'name' => $this->brand->name] : null),
            'provider_id' => $this->provider_id,
            'provider' => $this->whenLoaded('provider', fn () => $this->provider ? ['id' => $this->provider->id, 'name' => $this->provider->name] : null),
            'quantity' => $this->quantity,
            'min_quantity' => $this->min_quantity,
            'is_low' => $this->isLow(),
            'location' => $this->location,
            'weight' => $this->weight,
            'image_url' => $this->image_path ? Storage::disk('public')->url($this->image_path) : null,
            'is_active' => $this->is_active,
            $this->mergeWhen((bool) $user?->can('prices.view'), fn () => ['unit_price' => $this->unitPrice()]),
            $this->mergeWhen((bool) $user?->can('costs.view'), fn () => [
                'unit_cost' => $this->unit_cost,
                'coefficient' => $this->coefficient,
                'stock_value' => (int) round(max(0, $this->quantity) * $this->unit_cost),
            ]),
            'dependencies' => $this->whenLoaded('dependencies', fn () => $this->dependencies->map(fn ($d) => [
                'id' => $d->id,
                'designation' => $d->designation,
                'reference' => $d->reference,
                'unit' => $d->unit,
                'quantity' => (float) $d->pivot->quantity,
                'stock' => $d->quantity,
            ])->values()),
            'movements' => $this->whenLoaded('movements', fn () => StockMovementResource::collection($this->movements)->resolve($request)),
            'can' => [
                'update' => (bool) $user?->can('update', $this->resource),
                'delete' => (bool) $user?->can('delete', $this->resource),
                'move' => (bool) $user?->can('move', $this->resource),
            ],
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
        ];
    }
}
