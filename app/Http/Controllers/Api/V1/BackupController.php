<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\Backup\Backups;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Sauvegardes (backups.manage, administrateurs) : liste, sauvegarde immédiate, téléchargement, suppression. */
class BackupController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->allow($request);

        return response()->json(['data' => Backups::list(), 'meta' => ['keep_days' => (int) config('dash4.backups.keep_days')]]);
    }

    /** POST — sauvegarde la base tout de suite (`files` : les fichiers aussi). */
    public function store(Request $request): JsonResponse
    {
        $this->allow($request);
        @set_time_limit(600);
        $result = Backups::run($request->boolean('files'));
        activity('backups')->causedBy($request->user())->log('Sauvegarde manuelle');

        return response()->json(['data' => $result, 'message' => 'Sauvegarde terminée.'], 201);
    }

    public function download(Request $request, string $name): StreamedResponse
    {
        $this->allow($request);
        abort_unless(Backups::isValidName($name) && Backups::disk()->exists(Backups::path($name)), 404, 'Sauvegarde introuvable.');
        activity('backups')->causedBy($request->user())->withProperties(['name' => $name])->log('Sauvegarde téléchargée');

        return Backups::disk()->download(Backups::path($name), $name);
    }

    public function destroy(Request $request, string $name): JsonResponse
    {
        $this->allow($request);
        abort_unless(Backups::isValidName($name) && Backups::disk()->exists(Backups::path($name)), 404, 'Sauvegarde introuvable.');
        Backups::disk()->delete(Backups::path($name));
        activity('backups')->causedBy($request->user())->withProperties(['name' => $name])->log('Sauvegarde supprimée');

        return response()->json(['message' => 'Sauvegarde supprimée.']);
    }

    private function allow(Request $request): void
    {
        abort_unless($request->user()->can('backups.manage'), 403, "Vous n'avez pas le droit de faire cette action.");
    }
}
