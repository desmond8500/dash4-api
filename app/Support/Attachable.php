<?php

namespace App\Support;

use App\Models\Article;
use App\Models\Brand;
use App\Models\Building;
use App\Models\Client;
use App\Models\Equipment;
use App\Models\InstalledSystem;
use App\Models\ReceptionReport;
use App\Models\Sheet;
use App\Models\Comment;
use App\Models\Contract;
use App\Models\ClientRequest;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Journal;
use App\Models\Message;
use App\Models\JournalSection;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Purchase;
use App\Models\Quote;
use App\Models\Task;
use App\Models\TechShare;
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
        'brands' => Brand::class,
        'providers' => Provider::class,
        'purchases' => Purchase::class,
        'expenses' => Expense::class,
        'tasks' => Task::class,
        'client-requests' => ClientRequest::class,
        'journals' => Journal::class,
        'journal-sections' => JournalSection::class,
        'tech-shares' => TechShare::class,
        'comments' => Comment::class,
        'buildings' => Building::class,
        'installed-systems' => InstalledSystem::class,
        'equipment' => Equipment::class,
        'reception-reports' => ReceptionReport::class,
        'sheets' => Sheet::class,
        'contracts' => Contract::class,
        'messages' => Message::class,
    ];

    /** Fiches qui ont un fil de commentaires (pas les sections de journal, ni les commentaires eux-mêmes). */
    public const COMMENTABLE = [
        'clients', 'projects', 'quotes', 'invoices', 'articles', 'brands', 'providers', 'purchases', 'expenses',
        'tasks', 'client-requests', 'journals', 'tech-shares', 'installed-systems', 'equipment', 'reception-reports', 'contracts',
    ];

    /** Nom de la fiche dans l'URL (« projects »…) à partir du modèle. */
    public static function typeOf(Model $model): ?string
    {
        $key = array_search($model::class, self::TYPES, true);

        return $key === false ? null : $key;
    }

    /** Libellé court de la fiche, pour les notifications. */
    public static function label(Model $model): string
    {
        return (string) ($model->getAttribute('title') ?? $model->getAttribute('name') ?? $model->getAttribute('number')
            ?? $model->getAttribute('subject') ?? $model->getAttribute('label') ?? ('#'.$model->getKey()));
    }

    /** Supprimer un commentaire : son auteur, ou qui peut supprimer la fiche. */
    public static function canDeleteComment(User $user, Comment $comment, Model $parent): bool
    {
        return $comment->created_by === $user->id || Gate::forUser($user)->allows('delete', $parent);
    }

    public static function find(string $type, int $id): Model
    {
        $class = self::TYPES[$type] ?? abort(404, "Type de fiche inconnu : {$type}.");

        return $class::query()->findOrFail($id);
    }

    public static function authorizeView(User $user, Model $parent): void
    {
        Gate::forUser($user)->authorize('view', self::subject($parent));
    }

    /** Fiche dont on applique les droits : une section de journal suit son journal. */
    public static function subject(Model $parent): Model
    {
        return match (true) {
            $parent instanceof JournalSection => $parent->journal,
            $parent instanceof Comment => self::subject($parent->commentable()->withTrashed()->firstOrFail()),
            default => $parent,
        };
    }

    /** Ajouter : qui peut modifier la fiche ; sur une tâche, aussi les personnes affectées (notes, photos). */
    public static function authorizeAdd(User $user, Model $parent): void
    {
        // Pièces jointes d'un commentaire : son auteur seulement, s'il voit encore la fiche
        if ($parent instanceof Comment) {
            abort_unless($parent->created_by === $user->id, 403, 'Seul l\'auteur du commentaire peut y joindre des fichiers.');
            Gate::forUser($user)->authorize('view', self::subject($parent));

            return;
        }
        // Fichiers d'un message : son auteur, membre de la conversation
        if ($parent instanceof Message) {
            Gate::forUser($user)->authorize('update', $parent);

            return;
        }
        $parent = self::subject($parent);
        Gate::forUser($user)->authorize($parent instanceof Task ? 'progress' : 'update', $parent);
    }

    /**
     * Modifier ou supprimer une note, un lien ou un document : son auteur (s'il peut encore
     * modifier la fiche), ou quelqu'un qui peut supprimer la fiche (administrateur, manager).
     */
    public static function authorizeChange(User $user, Model $attachment, Model $parent): void
    {
        $gate = Gate::forUser($user);
        if ($parent instanceof Comment) {
            abort_unless(self::canDeleteComment($user, $parent, self::subject($parent)), 403, "Vous n'avez pas le droit de faire cette action.");

            return;
        }
        if ($parent instanceof Message) {
            $gate->authorize('update', $parent);

            return;
        }
        $parent = self::subject($parent);
        $allowed = $gate->allows('delete', $parent)
            || ($attachment->created_by === $user->id && $gate->allows($parent instanceof Task ? 'progress' : 'update', $parent));

        abort_unless($allowed, 403, "Vous n'avez pas le droit de faire cette action.");
    }
}
