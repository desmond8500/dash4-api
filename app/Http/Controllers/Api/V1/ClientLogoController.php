<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ClientLogoRequest;
use App\Http\Resources\ClientResource;
use App\Models\Client;
use Illuminate\Support\Facades\Storage;

/**
 * Logo d'un client, stocké sur le disque public (storage/app/public/clients/logos)
 * et servi par le lien public/storage (`php artisan storage:link`).
 */
class ClientLogoController extends Controller
{
    /** POST /api/v1/clients/{client}/logo (multipart, champ `logo`) — remplace le logo existant. */
    public function store(ClientLogoRequest $request, Client $client): ClientResource
    {
        $this->authorize('update', $client);

        $old = $client->logo_path;
        $path = $request->file('logo')->store('clients/logos', 'public');

        $client->forceFill(['logo_path' => $path])->save();

        if ($old && $old !== $path) {
            Storage::disk('public')->delete($old);
        }

        return new ClientResource($client->refresh()->load(['phones', 'emails'])->loadCount('contacts'));
    }

    /** DELETE /api/v1/clients/{client}/logo */
    public function destroy(Client $client): ClientResource
    {
        $this->authorize('update', $client);

        if ($client->logo_path) {
            Storage::disk('public')->delete($client->logo_path);
            $client->forceFill(['logo_path' => null])->save();
        }

        return new ClientResource($client->refresh()->load(['phones', 'emails'])->loadCount('contacts'));
    }
}
