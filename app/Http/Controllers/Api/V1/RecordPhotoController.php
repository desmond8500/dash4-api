<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ToolResource;
use App\Http\Resources\VehicleResource;
use App\Models\Tool;
use App\Models\Vehicle;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * Photo principale d'une fiche (véhicule, outil), affichée dans les listes et en tête de la fiche.
 * Les autres photos sont des documents image de la fiche (galerie).
 * Véhicule : qui gère le parc, ou son conducteur attitré ; outil : qui peut modifier l'outillage.
 */
class RecordPhotoController extends Controller
{
    private const TYPES = [
        'vehicles' => [Vehicle::class, 'vehicles/photos', 'addPhotos'],
        'tools' => [Tool::class, 'tools/photos', 'update'],
    ];

    /** POST /api/v1/{vehicles|tools}/{id}/photo (multipart, champ `photo`) */
    public function store(Request $request, string $type, int $id): JsonResource
    {
        [$record, $folder] = $this->find($type, $id);
        $request->validate(['photo' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,heic', 'max:10240']], [], ['photo' => 'photo']);
        $old = $record->photo_path;
        $record->forceFill(['photo_path' => $request->file('photo')->store($folder, 'public')])->save();
        if ($old) {
            Storage::disk('public')->delete($old);
        }

        return $this->resource($record);
    }

    /** DELETE /api/v1/{vehicles|tools}/{id}/photo */
    public function destroy(string $type, int $id): JsonResource
    {
        [$record] = $this->find($type, $id);
        if ($record->photo_path) {
            Storage::disk('public')->delete($record->photo_path);
            $record->forceFill(['photo_path' => null])->save();
        }

        return $this->resource($record);
    }

    /** @return array{0: Vehicle|Tool, 1: string} */
    private function find(string $type, int $id): array
    {
        [$class, $folder, $ability] = self::TYPES[$type] ?? abort(404);
        $record = $class::query()->findOrFail($id);
        $this->authorize($ability, $record);

        return [$record, $folder];
    }

    private function resource(Vehicle|Tool $record): JsonResource
    {
        if ($record instanceof Vehicle) {
            return new VehicleResource($record->refresh()->load(['driver:id,firstname,lastname', 'papers', 'maintenances']));
        }

        return new ToolResource($record->refresh()->load(['article.brand:id,name', 'storageLocation:id,name', 'currentLoan']));
    }
}
