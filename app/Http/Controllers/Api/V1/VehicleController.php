<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ExpenseStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\VehicleResource;
use App\Models\Expense;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleMaintenance;
use App\Models\VehicleMileage;
use App\Models\VehiclePaper;
use App\Support\Fleet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Parc automobile (`vehicles.*`) : véhicules et scooters, papiers (assurance, carte grise, CMC…),
 * relevés de kilométrage (aussi par le conducteur attitré, hors ligne avec `client_uuid`) et entretiens.
 */
class VehicleController extends Controller
{
    private static function full(): array
    {
        return [
            'driver:id,firstname,lastname',
            'papers' => fn ($q) => $q->withCount('documents'),
            'mileages' => fn ($q) => $q->with('creator:id,firstname,lastname')->limit(100),
            'maintenances',
        ];
    }

    /** GET /api/v1/vehicles — filtres : kind, status, driver_id, alerts=1 (papiers ou entretiens à surveiller), search. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Vehicle::class);
        $vehicles = Vehicle::query()->visibleTo($request->user())
            ->with(['driver:id,firstname,lastname', 'papers', 'maintenances'])
            ->when($request->filled('kind'), fn ($q) => $q->where('kind', $request->string('kind')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('driver_id'), fn ($q) => $q->where('driver_id', $request->integer('driver_id')))
            ->when($request->filled('search'), function ($q) use ($request) {
                foreach (preg_split('/\s+/', trim($request->string('search')->toString())) as $word) {
                    $term = '%'.$word.'%';
                    $q->where(fn ($w) => $w->where('name', 'like', $term)->orWhere('plate', 'like', $term)
                        ->orWhere('brand', 'like', $term)->orWhere('model', 'like', $term));
                }
            })
            ->orderByRaw("CASE status WHEN 'active' THEN 0 WHEN 'repair' THEN 1 WHEN 'out_of_service' THEN 2 ELSE 3 END")
            ->orderBy('name')
            ->get();
        if ($request->boolean('alerts')) {
            $vehicles = $vehicles->filter(fn (Vehicle $v) => Fleet::alerts($v) !== [])->values();
        }

        return VehicleResource::collection($vehicles);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Vehicle::class);
        $data = $this->validated($request, null);
        if (! $request->user()->can('costs.view')) {
            unset($data['purchase_price']);
        }
        $vehicle = new Vehicle(array_diff_key($data, ['mileage' => true, 'mileage_start' => true]));
        $vehicle->created_by = $request->user()->id;
        $vehicle->save();
        if ($request->filled('mileage')) {
            $vehicle->mileages()->forceCreate(['vehicle_id' => $vehicle->id, 'read_on' => today(), 'km' => $request->integer('mileage'), 'note' => 'Kilométrage à l\'entrée dans le parc', 'created_by' => $request->user()->id]);
            $vehicle->refreshMileage();
        }

        return (new VehicleResource($vehicle->refresh()->load(self::full())))->response()->setStatusCode(201);
    }

    public function show(Vehicle $vehicle): VehicleResource
    {
        $this->authorize('view', $vehicle);

        return new VehicleResource($vehicle->load(self::full()));
    }

    public function update(Request $request, Vehicle $vehicle): VehicleResource
    {
        $this->authorize('update', $vehicle);
        $data = $this->validated($request, $vehicle);
        if (! $request->user()->can('costs.view')) {
            unset($data['purchase_price']);
        }
        $vehicle->update($data);

        return new VehicleResource($vehicle->refresh()->load(self::full()));
    }

    public function destroy(Vehicle $vehicle): JsonResponse
    {
        $this->authorize('delete', $vehicle);
        $vehicle->delete();

        return response()->json(['message' => 'Véhicule supprimé.']);
    }

    // --- Papiers -------------------------------------------------------------------------------

    public function storePaper(Request $request, Vehicle $vehicle): JsonResponse
    {
        $this->authorize('update', $vehicle);
        $paper = new VehiclePaper($this->validatedPaper($request, true));
        $paper->forceFill(['vehicle_id' => $vehicle->id, 'created_by' => $request->user()->id])->save();

        return (new VehicleResource($vehicle->refresh()->load(self::full())))->response()->setStatusCode(201);
    }

    public function updatePaper(Request $request, Vehicle $vehicle, VehiclePaper $paper): VehicleResource
    {
        $this->authorize('update', $vehicle);
        abort_unless($paper->vehicle_id === $vehicle->id, 404);
        $paper->fill($this->validatedPaper($request, false));
        if ($paper->isDirty('expires_on')) {
            $paper->alert_level = 0;
        }
        $paper->save();

        return new VehicleResource($vehicle->refresh()->load(self::full()));
    }

    public function destroyPaper(Vehicle $vehicle, VehiclePaper $paper): VehicleResource
    {
        $this->authorize('update', $vehicle);
        abort_unless($paper->vehicle_id === $vehicle->id, 404);
        $paper->delete();

        return new VehicleResource($vehicle->refresh()->load(self::full()));
    }

    // --- Kilométrage ---------------------------------------------------------------------------

    /** POST /vehicles/{id}/mileages — `client_uuid` : un renvoi depuis le mobile ne crée pas de doublon. */
    public function storeMileage(Request $request, Vehicle $vehicle): JsonResponse
    {
        $this->authorize('recordMileage', $vehicle);
        $data = $request->validate([
            'client_uuid' => ['sometimes', 'nullable', 'uuid'],
            'read_on' => ['sometimes', 'date', 'before_or_equal:today'],
            'km' => ['required', 'integer', 'min:0', 'max:9999999'],
            'note' => ['sometimes', 'nullable', 'string', 'max:255'],
        ], [], ['read_on' => 'date', 'km' => 'kilométrage']);
        if (! empty($data['client_uuid']) && VehicleMileage::query()->where('client_uuid', $data['client_uuid'])->exists()) {
            return (new VehicleResource($vehicle->load(self::full())))->response()->setStatusCode(200);
        }
        $readOn = $data['read_on'] ?? today()->toDateString();
        // Un compteur ne recule pas : comparé aux relevés d'avant et d'après cette date
        $before = (int) $vehicle->mileages()->whereDate('read_on', '<=', $readOn)->max('km');
        $after = $vehicle->mileages()->whereDate('read_on', '>', $readOn)->min('km');
        abort_if($data['km'] < $before, 422, "Le compteur ne peut pas reculer : un relevé de {$before} km existe déjà à cette date ou avant.");
        abort_if($after !== null && $data['km'] > $after, 422, "Un relevé plus récent indique {$after} km : vérifiez la date ou le kilométrage.");

        $vehicle->mileages()->forceCreate([...$data, 'vehicle_id' => $vehicle->id, 'read_on' => $readOn, 'created_by' => $request->user()->id]);
        $vehicle->refreshMileage();

        return (new VehicleResource($vehicle->refresh()->load(self::full())))->response()->setStatusCode(201);
    }

    public function destroyMileage(Vehicle $vehicle, VehicleMileage $mileage): VehicleResource
    {
        $this->authorize('update', $vehicle);
        abort_unless($mileage->vehicle_id === $vehicle->id, 404);
        $mileage->delete();
        $vehicle->refreshMileage();

        return new VehicleResource($vehicle->refresh()->load(self::full()));
    }

    // --- Entretiens ----------------------------------------------------------------------------

    /**
     * POST /vehicles/{id}/maintenances — `create_expense=1` : le coût est aussi enregistré en dépense
     * (validée, rattachée au véhicule), pour la comptabilité.
     */
    public function storeMaintenance(Request $request, Vehicle $vehicle): JsonResponse
    {
        $this->authorize('update', $vehicle);
        $data = $this->validatedMaintenance($request, true);
        DB::transaction(function () use ($vehicle, $data, $request) {
            $maintenance = new VehicleMaintenance(array_diff_key($data, ['create_expense' => true]));
            $maintenance->forceFill(['vehicle_id' => $vehicle->id, 'created_by' => $request->user()->id]);
            if (($data['create_expense'] ?? false) && ($data['cost'] ?? 0) > 0) {
                $maintenance->expense_id = $this->expenseFor($request->user(), $vehicle, $maintenance)->id;
            }
            $maintenance->save();
        });
        $vehicle->refreshMileage();

        return (new VehicleResource($vehicle->refresh()->load(self::full())))->response()->setStatusCode(201);
    }

    public function updateMaintenance(Request $request, Vehicle $vehicle, VehicleMaintenance $maintenance): VehicleResource
    {
        $this->authorize('update', $vehicle);
        abort_unless($maintenance->vehicle_id === $vehicle->id, 404);
        $maintenance->fill(array_diff_key($this->validatedMaintenance($request, false), ['create_expense' => true]));
        if ($maintenance->isDirty(['next_on', 'next_km'])) {
            $maintenance->alert_state = null;
        }
        $maintenance->save();
        $vehicle->refreshMileage();

        return new VehicleResource($vehicle->refresh()->load(self::full()));
    }

    public function destroyMaintenance(Vehicle $vehicle, VehicleMaintenance $maintenance): VehicleResource
    {
        $this->authorize('update', $vehicle);
        abort_unless($maintenance->vehicle_id === $vehicle->id, 404);
        $maintenance->delete();
        $vehicle->refreshMileage();

        return new VehicleResource($vehicle->refresh()->load(self::full()));
    }

    /** GET /vehicles/options — listes de valeurs pour les formulaires. */
    public function options(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Vehicle::class);
        $list = fn (array $values) => collect($values)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values();

        return response()->json(['data' => [
            'kinds' => $list(Fleet::KINDS),
            'fuels' => $list(Fleet::FUELS),
            'statuses' => $list(Fleet::STATUSES),
            'paper_types' => $list(Fleet::PAPER_TYPES),
            'maintenance_types' => $list(Fleet::MAINTENANCE_TYPES),
        ]]);
    }

    private function expenseFor(User $user, Vehicle $vehicle, VehicleMaintenance $maintenance): Expense
    {
        abort_unless($user->can('accounting.create'), 403, "Vous n'avez pas le droit d'enregistrer la dépense : décochez l'option ou demandez au comptable.");
        $expense = new Expense([
            'label' => (Fleet::MAINTENANCE_TYPES[$maintenance->type] ?? 'Entretien')." — {$vehicle->name}".($vehicle->plate ? " ({$vehicle->plate})" : ''),
            'description' => $maintenance->description,
            'amount' => $maintenance->cost,
            'spent_at' => $maintenance->done_on,
            'vehicle_id' => $vehicle->id,
        ]);
        $expense->forceFill(['created_by' => $user->id, 'status' => ExpenseStatus::Approved, 'decided_by' => $user->id, 'decided_at' => now()])->save();

        return $expense;
    }

    private function validated(Request $request, ?Vehicle $vehicle): array
    {
        $required = $vehicle ? 'sometimes' : 'required';

        return $request->validate([
            'kind' => [$required, Rule::in(array_keys(Fleet::KINDS))],
            'name' => [$required, 'string', 'max:255'],
            'brand' => ['sometimes', 'nullable', 'string', 'max:100'],
            'model' => ['sometimes', 'nullable', 'string', 'max:100'],
            'plate' => ['sometimes', 'nullable', 'string', 'max:30', Rule::unique('vehicles', 'plate')->ignore($vehicle?->id)],
            'vin' => ['sometimes', 'nullable', 'string', 'max:50'],
            'year' => ['sometimes', 'nullable', 'integer', 'min:1950', 'max:'.(today()->year + 1)],
            'color' => ['sometimes', 'nullable', 'string', 'max:50'],
            'fuel' => ['sometimes', 'nullable', Rule::in(array_keys(Fleet::FUELS))],
            'fiscal_power' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:999'],
            'seats' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:99'],
            'status' => ['sometimes', Rule::in(array_keys(Fleet::STATUSES))],
            'driver_id' => ['sometimes', 'nullable', 'integer', Rule::exists('users', 'id')],
            'acquired_on' => ['sometimes', 'nullable', 'date'],
            'purchase_price' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:999999999999'],
            'mileage' => [$vehicle ? 'prohibited' : 'sometimes', 'integer', 'min:0', 'max:9999999'],
            'service_interval_km' => ['sometimes', 'nullable', 'integer', 'min:100', 'max:200000'],
            'service_interval_months' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:60'],
            'characteristics' => ['sometimes', 'nullable', 'array', 'max:40'],
            'characteristics.*.label' => ['required', 'string', 'max:100'],
            'characteristics.*.value' => ['nullable', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:10000'],
        ], [
            'mileage.prohibited' => 'Le kilométrage se met à jour par un relevé du compteur.',
        ], [
            'kind' => 'type', 'name' => 'nom', 'brand' => 'marque', 'model' => 'modèle', 'plate' => 'immatriculation', 'vin' => 'numéro de châssis',
            'year' => 'année', 'color' => 'couleur', 'fuel' => 'énergie', 'fiscal_power' => 'puissance fiscale', 'seats' => 'places',
            'status' => 'état', 'driver_id' => 'conducteur', 'acquired_on' => "date d'acquisition", 'purchase_price' => "prix d'achat",
            'mileage' => 'kilométrage', 'service_interval_km' => 'intervalle de révision (km)', 'service_interval_months' => 'intervalle de révision (mois)',
            'characteristics' => 'caractéristiques', 'notes' => 'notes',
        ]);
    }

    private function validatedPaper(Request $request, bool $creating): array
    {
        $data = $request->validate([
            'type' => [$creating ? 'required' : 'sometimes', Rule::in(array_keys(Fleet::PAPER_TYPES))],
            'label' => ['sometimes', 'nullable', 'string', 'max:255'],
            'number' => ['sometimes', 'nullable', 'string', 'max:100'],
            'provider' => ['sometimes', 'nullable', 'string', 'max:255'],
            'issued_on' => ['sometimes', 'nullable', 'date'],
            'expires_on' => ['sometimes', 'nullable', 'date', 'after_or_equal:issued_on'],
            'cost' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:999999999999'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ], [], [
            'type' => 'type de papier', 'label' => 'intitulé', 'number' => 'numéro', 'provider' => 'organisme', 'issued_on' => 'date de délivrance',
            'expires_on' => "date d'expiration", 'cost' => 'coût', 'notes' => 'notes',
        ]);
        if (! $request->user()->can('costs.view')) {
            unset($data['cost']);
        }

        return $data;
    }

    private function validatedMaintenance(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';
        $data = $request->validate([
            'type' => [$required, Rule::in(array_keys(Fleet::MAINTENANCE_TYPES))],
            'done_on' => [$required, 'date', 'before_or_equal:today'],
            'km' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:9999999'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'garage' => ['sometimes', 'nullable', 'string', 'max:255'],
            'cost' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:999999999999'],
            'next_on' => ['sometimes', 'nullable', 'date', 'after:done_on'],
            'next_km' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:9999999', 'gt:km'],
            'create_expense' => ['sometimes', 'boolean'],
        ], [], [
            'type' => "type d'entretien", 'done_on' => 'date', 'km' => 'kilométrage', 'description' => 'description', 'garage' => 'garage',
            'cost' => 'coût', 'next_on' => 'prochain entretien (date)', 'next_km' => 'prochain entretien (km)',
        ]);
        if (! $request->user()->can('costs.view')) {
            unset($data['cost'], $data['create_expense']);
        }

        return $data;
    }
}
