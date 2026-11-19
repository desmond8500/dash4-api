<?php

namespace App\Support;

use App\Models\ClientRequestLink;
use App\Models\DeliveryNote;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\Quote;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/** Fiches qu'une demande client peut lier : tâches, factures, bordereaux, devis, projets. */
final class ClientRequestLinks
{
    public const TYPES = [
        'task' => Task::class,
        'invoice' => Invoice::class,
        'delivery_note' => DeliveryNote::class,
        'quote' => Quote::class,
        'project' => Project::class,
    ];

    public const LABELS = ['task' => 'Tâche', 'invoice' => 'Facture', 'delivery_note' => 'Bordereau', 'quote' => 'Devis', 'project' => 'Projet'];

    /** @return array{id: int, type: string, type_label: string, target_id: int, label: string, quote_id: ?int}|null */
    public static function present(ClientRequestLink $link): ?array
    {
        $target = $link->linkable;
        if (! $target) {
            return null;
        }

        return [
            'id' => $link->id,
            'type' => $link->linkable_type,
            'type_label' => self::LABELS[$link->linkable_type] ?? $link->linkable_type,
            'target_id' => $target->getKey(),
            'label' => self::label($target),
            'quote_id' => $target instanceof DeliveryNote ? $target->quote_id : null,
        ];
    }

    public static function label(Model $target): string
    {
        return match (true) {
            $target instanceof Task => $target->title,
            $target instanceof Invoice => trim(($target->number ?? 'Brouillon').' — '.$target->title),
            $target instanceof DeliveryNote => $target->number.' — '.$target->title,
            $target instanceof Quote => $target->number.' — '.$target->title,
            $target instanceof Project => $target->name,
            default => (string) $target->getKey(),
        };
    }

    /** Peut-on voir cette fiche (et donc la lier) ? */
    public static function visible(User $user, string $type, int $id): ?Model
    {
        $target = self::query($user, $type)?->whereKey($id)->first();

        return $target;
    }

    /** @return Collection<int, array{id: int, label: string}> */
    public static function search(User $user, string $type, string $term): Collection
    {
        $like = '%'.$term.'%';
        $query = self::query($user, $type);
        if (! $query) {
            return collect();
        }
        $query->where(fn ($q) => match ($type) {
            'task' => $q->where('title', 'like', $like),
            'invoice' => $q->where('number', 'like', $like)->orWhere('title', 'like', $like),
            'delivery_note' => $q->where('number', 'like', $like)->orWhere('title', 'like', $like),
            'quote' => $q->where('number', 'like', $like)->orWhere('title', 'like', $like),
            default => $q->where('name', 'like', $like),
        });

        return $query->latest('id')->limit(15)->get()->map(fn (Model $m) => ['id' => $m->getKey(), 'label' => self::label($m)]);
    }

    private static function query(User $user, string $type): ?\Illuminate\Database\Eloquent\Builder
    {
        return match ($type) {
            'task' => $user->canAny(['tasks.view', 'tasks.view.own']) ? Task::query()->visibleTo($user) : null,
            'invoice' => $user->can('invoices.view') && ! $user->isTechnician() ? Invoice::query() : null,
            'delivery_note' => $user->canAny(['quotes.view', 'quotes.view.own', 'delivery_notes.view'])
                ? DeliveryNote::query()->with('quote:id,title')->whereHas('quote', fn ($q) => $user->can('delivery_notes.view') ? $q : $q->visibleTo($user))
                : null,
            'quote' => $user->canAny(['quotes.view', 'quotes.view.own']) ? Quote::query()->visibleTo($user) : null,
            'project' => $user->canAny(['projects.view', 'projects.view.own']) ? Project::query()->visibleTo($user) : null,
            default => null,
        };
    }
}
