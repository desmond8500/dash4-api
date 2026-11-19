<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CommentResource;
use App\Models\Comment;
use App\Models\User;
use App\Notifications\CommentMentionNotification;
use App\Support\Attachable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Fil de commentaires d'une fiche : /api/v1/{type}/{id}/comments, puis /api/v1/comments/{comment}.
 * Qui voit la fiche peut commenter. Les mentions (`mentions` : ids) préviennent les personnes
 * qui voient la fiche ; les autres sont ignorées. Pièces jointes : /api/v1/comments/{id}/documents.
 */
class CommentController extends Controller
{
    private const WITH = ['author:id,firstname,lastname', 'mentions:id,firstname,lastname', 'documents', 'commentable'];

    /** GET — du plus ancien au plus récent ; `updated_since` pour la synchro (suppressions comprises). */
    public function index(Request $request, string $type, int $id): AnonymousResourceCollection
    {
        $parent = $this->parent($type, $id);
        Attachable::authorizeView($request->user(), $parent);

        $query = Comment::query()->whereMorphedTo('commentable', $parent)->with(self::WITH)->orderBy('created_at')->orderBy('id');
        if ($request->filled('updated_since')) {
            $query->withTrashed()->where('updated_at', '>', $request->date('updated_since'));
        }

        return CommentResource::collection($query->limit(500)->get())->additional(['meta' => ['server_time' => now()->toIso8601String()]]);
    }

    /** POST — `body`, `mentions` (ids), `client_uuid` (envoi hors ligne sans doublon). */
    public function store(Request $request, string $type, int $id): JsonResponse
    {
        $parent = $this->parent($type, $id);
        $user = $request->user();
        Attachable::authorizeView($user, $parent);
        $data = $this->validated($request, true);

        if (! empty($data['client_uuid']) && $existing = Comment::withTrashed()->where('client_uuid', $data['client_uuid'])->first()) {
            return (new CommentResource($existing->load(self::WITH)))->response()->setStatusCode(200);
        }

        $comment = DB::transaction(function () use ($parent, $data, $user) {
            $comment = new Comment(['client_uuid' => $data['client_uuid'] ?? null, 'body' => $data['body']]);
            $comment->commentable()->associate($parent);
            $comment->created_by = $user->id;
            $comment->save();

            return $comment;
        });
        $this->mention($comment, $parent, $type, $data['mentions'] ?? [], $user);
        $parent->touch();

        return (new CommentResource($comment->load(self::WITH)))->response()->setStatusCode(201);
    }

    /** PATCH — l'auteur seulement ; les personnes nouvellement mentionnées sont prévenues. */
    public function update(Request $request, Comment $comment): CommentResource
    {
        $user = $request->user();
        abort_unless($comment->created_by === $user->id, 403, 'Seul son auteur peut modifier un commentaire.');
        $parent = $comment->commentable()->withTrashed()->firstOrFail();
        Attachable::authorizeView($user, $parent);
        $data = $this->validated($request, false);

        if (array_key_exists('body', $data) && $data['body'] !== $comment->body) {
            $comment->forceFill(['body' => $data['body'], 'edited_at' => now()])->save();
        }
        if (array_key_exists('mentions', $data)) {
            $this->mention($comment, $parent, Attachable::typeOf($parent) ?? '', $data['mentions'], $user);
        }
        $parent->touch();

        return new CommentResource($comment->refresh()->load(self::WITH));
    }

    public function destroy(Request $request, Comment $comment): JsonResponse
    {
        $parent = $comment->commentable()->withTrashed()->firstOrFail();
        abort_unless(Attachable::canDeleteComment($request->user(), $comment, Attachable::subject($parent)), 403, "Vous n'avez pas le droit de faire cette action.");
        $comment->delete();
        $parent->touch();

        return response()->json(['message' => 'Commentaire supprimé.']);
    }

    /** GET /api/v1/{type}/{id}/mentionable — personnes actives qui voient la fiche (à mentionner). */
    public function mentionable(Request $request, string $type, int $id): JsonResponse
    {
        $parent = $this->parent($type, $id);
        Attachable::authorizeView($request->user(), $parent);

        return response()->json(['data' => $this->viewers($parent)
            ->reject(fn (User $u) => $u->id === $request->user()->id)
            ->map(fn (User $u) => ['id' => $u->id, 'full_name' => $u->full_name, 'job_title' => $u->job_title])
            ->values()]);
    }

    private function parent(string $type, int $id): Model
    {
        abort_unless(in_array($type, Attachable::COMMENTABLE, true), 404, "Cette fiche n'a pas de commentaires.");

        return Attachable::find($type, $id);
    }

    /** @return Collection<int, User> */
    private function viewers(Model $parent): Collection
    {
        $subject = Attachable::subject($parent);

        return User::query()->where('is_active', true)->orderBy('firstname')->orderBy('lastname')->get()
            ->filter(fn (User $u) => Gate::forUser($u)->allows('view', $subject))
            ->values();
    }

    /** Ajoute les mentions valables (personnes qui voient la fiche) et prévient les nouvelles. */
    private function mention(Comment $comment, Model $parent, string $type, array $ids, User $by): void
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $allowed = $ids ? $this->viewers($parent)->whereIn('id', $ids)->pluck('id')->all() : [];
        $added = $comment->mentions()->sync($allowed)['attached'];

        $comment->load('author');
        User::query()->whereKey(array_diff($added, [$by->id]))->get()
            ->each->notify(new CommentMentionNotification($comment, $type, (int) $parent->getKey(), Attachable::label($parent)));
    }

    private function validated(Request $request, bool $creating): array
    {
        return $request->validate([
            'client_uuid' => ['sometimes', 'nullable', 'uuid'],
            'body' => [$creating ? 'required' : 'sometimes', 'string', 'max:10000'],
            'mentions' => ['sometimes', 'array', 'max:30'],
            'mentions.*' => ['integer'],
        ], ['body.required' => 'Écrivez votre commentaire.'], ['body' => 'commentaire', 'mentions' => 'mentions']);
    }
}
