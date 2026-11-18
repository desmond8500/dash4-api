<?php

namespace App\Support;

use App\Models\Article;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Quote;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * Fiches qui acceptent notes, liens et documents, telles qu'elles apparaissent dans l'URL :
 * /api/v1/{type}/{id}/notes. Les droits sont ceux de la fiche :
 * la voir pour lire, pouvoir la modifier pour ajouter.
 */
final class Attachable
{
    /** @var array<string, class-string<Model>> */
    public const TYPES = [
        'clients' => Client::class,
        'projects' => Project::class,
        'quotes' => Quote::class,
        'invoices' => Invoice::class,
        'articles' => Article::class,
        'providers' => Provider::class,
    ];

    public static function find(string $type, int $id): Model
    {
        $class = self::TYPES[$type] ?? abort(404, "Type de fiche inconnu : {$type}.");

        return $class::query()->findOrFail($id);
    }

    public static function authorizeView(User $user, Model $parent): void
    {
        Gate::forUser($user)->authorize('view', $parent);
    }

    public static function authorizeAdd(User $user, Model $parent): void
    {
        Gate::forUser($user)->authorize('update', $parent);
    }

    /**
     * Modifier ou supprimer une note, un lien ou un document : son auteur (s'il peut encore
     * modifier la fiche), ou quelqu'un qui peut supprimer la fiche (administrateur, manager).
     */
    public static function authorizeChange(User $user, Model $attachment, Model $parent): void
    {
        $gate = Gate::forUser($user);
        $allowed = $gate->allows('delete', $parent)
            || ($attachment->created_by === $user->id && $gate->allows('update', $parent));

        abort_unless($allowed, 403, "Vous n'avez pas le droit de faire cette action.");
    }
}
