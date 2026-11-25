<?php

namespace App\Http\Middleware;

use App\Support\Permissions;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Compte « Lecture seule » (démonstrations, maquette dash4webdemo) : seules les lectures passent.
 * Toute requête qui modifie quelque chose est refusée, même celles ouvertes à tout compte (profil, messages…),
 * sauf la déconnexion.
 */
class ReadOnlyViewer
{
    private const ALLOWED_WRITES = ['api.v1.auth.logout'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && ! $request->isMethodSafe() && $user->hasRole(Permissions::ROLE_VIEWER)
            && ! in_array($request->route()?->getName(), self::ALLOWED_WRITES, true)) {
            return response()->json(['message' => 'Compte en lecture seule : aucune modification possible.'], 403);
        }

        return $next($request);
    }
}
