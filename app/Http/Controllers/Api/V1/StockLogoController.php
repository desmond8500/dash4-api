<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ClientLogoRequest;
use App\Models\Brand;
use App\Models\Provider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

/** Logo d'une marque ou d'un fournisseur (JPEG, PNG, WebP, 2 Mo), sur le disque public. */
class StockLogoController extends Controller
{
    public function storeBrand(ClientLogoRequest $request, Brand $brand): JsonResponse
    {
        $this->store($request, $brand, 'brands/logos');

        return app(BrandController::class)->show($brand->refresh());
    }

    public function destroyBrand(Brand $brand): JsonResponse
    {
        $this->destroy($brand);

        return app(BrandController::class)->show($brand->refresh());
    }

    public function storeProvider(ClientLogoRequest $request, Provider $provider): JsonResponse
    {
        $this->store($request, $provider, 'providers/logos');

        return app(ProviderController::class)->show($provider->refresh());
    }

    public function destroyProvider(Provider $provider): JsonResponse
    {
        $this->destroy($provider);

        return app(ProviderController::class)->show($provider->refresh());
    }

    private function store(ClientLogoRequest $request, Model $model, string $folder): void
    {
        $this->authorize('update', $model);
        $old = $model->logo_path;
        $model->forceFill(['logo_path' => $request->file('logo')->store($folder, 'public')])->save();
        if ($old) {
            Storage::disk('public')->delete($old);
        }
    }

    private function destroy(Model $model): void
    {
        $this->authorize('update', $model);
        if ($model->logo_path) {
            Storage::disk('public')->delete($model->logo_path);
            $model->forceFill(['logo_path' => null])->save();
        }
    }
}
