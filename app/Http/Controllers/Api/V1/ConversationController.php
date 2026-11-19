<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ConversationResource;
use App\Http\Resources\MessageResource;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

/**
 * Messagerie interne : chacun (tous profils) écrit à qui il veut et ne voit que ses conversations.
 * Pas de processus permanent : les applications demandent régulièrement ce qui est nouveau
 * (`after_id`, `updated_since`, compteur de non lus).
 */
class ConversationController extends Controller
{
    private const MESSAGE_WITH = ['author:id,firstname,lastname,avatar_path', 'documents.creator:id,firstname,lastname'];

    /** GET /api/v1/conversations — les miennes, la plus active d'abord ; filtres `project_id`, `task_id`, `updated_since`. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $query = $this->listQuery($user)
            ->when($request->filled('project_id'), fn ($q) => $q->where('project_id', $request->integer('project_id')))
            ->when($request->filled('task_id'), fn ($q) => $q->where('task_id', $request->integer('task_id')))
            ->when($request->filled('updated_since'), fn ($q) => $q->where('conversations.updated_at', '>', $request->date('updated_since')));

        $items = $query->orderByRaw('coalesce(last_message_at, conversations.created_at) desc')->limit(300)->get();

        return ConversationResource::collection($items)->additional(['meta' => [
            'server_time' => now()->toIso8601String(),
            'unread_total' => $this->unreadTotal($user),
        ]]);
    }

    /** GET /api/v1/conversations/unread — compteur de messages non lus (pour le menu et l'onglet). */
    public function unread(Request $request): JsonResponse
    {
        return response()->json(['data' => ['count' => $this->unreadTotal($request->user())]]);
    }

    /** GET /api/v1/messaging/users — personnes à qui écrire (comptes actifs). */
    public function users(Request $request): JsonResponse
    {
        return response()->json(['data' => User::query()->where('is_active', true)->whereKeyNot($request->user()->id)
            ->orderBy('firstname')->orderBy('lastname')->get()
            ->map(fn (User $u) => [
                'id' => $u->id,
                'full_name' => $u->full_name,
                'job_title' => $u->job_title,
                'avatar_url' => $u->avatar_path ? Storage::disk('public')->url($u->avatar_path) : null,
            ])->values()]);
    }

    /**
     * POST /api/v1/conversations — `type` : direct (une personne dans `user_ids`, conversation reprise si elle existe)
     * ou group (`title`, `user_ids`, `project_id` ou `task_id` facultatifs). `client_uuid` : création hors ligne.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'client_uuid' => ['nullable', 'uuid'],
            'type' => ['required', 'in:direct,group'],
            'title' => ['nullable', 'string', 'max:255'],
            'user_ids' => ['required', 'array', 'min:1', 'max:100'],
            'user_ids.*' => ['integer'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'task_id' => ['nullable', 'integer', 'exists:tasks,id'],
        ], ['user_ids.required' => 'Choisissez au moins une personne.'], ['title' => 'nom du groupe', 'user_ids' => 'participants']);

        if (! empty($data['client_uuid']) && $existing = Conversation::findByUuid($data['client_uuid'])) {
            return (new ConversationResource($this->reload($existing, $user)))->response()->setStatusCode(200);
        }

        $ids = User::query()->where('is_active', true)->whereKey(array_diff(array_map('intval', $data['user_ids']), [$user->id]))->pluck('id')->all();
        abort_if($ids === [], 422, 'Choisissez au moins une autre personne active.');

        if ($data['type'] === 'direct') {
            abort_if(count($ids) !== 1, 422, 'Une conversation privée se fait à deux.');
            $key = Conversation::directKey($user->id, $ids[0]);
            $conversation = Conversation::query()->where('direct_key', $key)->first();
            $created = $conversation === null;
            if ($created) {
                $conversation = DB::transaction(function () use ($key, $user, $ids) {
                    $c = new Conversation;
                    $c->forceFill(['type' => 'direct', 'direct_key' => $key, 'created_by' => $user->id])->save();
                    $c->members()->attach([$user->id, $ids[0]]);

                    return $c;
                });
            }
            $conversation->rememberUuid($data['client_uuid'] ?? null);

            return (new ConversationResource($this->reload($conversation, $user)))->response()->setStatusCode($created ? 201 : 200);
        }

        $task = isset($data['task_id']) ? Task::query()->findOrFail($data['task_id']) : null;
        $project = isset($data['project_id']) ? Project::query()->findOrFail($data['project_id']) : ($task?->project_id ? Project::query()->find($task->project_id) : null);
        if ($task) {
            Gate::authorize('view', $task);
        }
        if ($project && ! $task) {
            Gate::authorize('view', $project);
        }

        $conversation = DB::transaction(function () use ($data, $user, $ids, $task, $project) {
            $c = new Conversation(['title' => $data['title'] ?? $task?->title ?? $project?->name ?? 'Groupe']);
            $c->forceFill(['type' => 'group', 'created_by' => $user->id, 'project_id' => $project?->id, 'task_id' => $task?->id])->save();
            $c->members()->attach([$user->id, ...$ids]);
            $c->rememberUuid($data['client_uuid'] ?? null);

            return $c;
        });

        return (new ConversationResource($this->reload($conversation, $user)))->response()->setStatusCode(201);
    }

    public function show(Request $request, Conversation $conversation): ConversationResource
    {
        $this->authorize('view', $conversation);

        return new ConversationResource($this->reload($conversation, $request->user()));
    }

    /** PATCH — nom du groupe (son créateur, ou un administrateur). */
    public function update(Request $request, Conversation $conversation): ConversationResource
    {
        $this->authorize('manage', $conversation);
        $conversation->update($request->validate(['title' => ['required', 'string', 'max:255']], [], ['title' => 'nom du groupe']));

        return new ConversationResource($this->reload($conversation, $request->user()));
    }

    /** POST /members — ajoute des personnes au groupe. */
    public function addMembers(Request $request, Conversation $conversation): ConversationResource
    {
        $this->authorize('manage', $conversation);
        $data = $request->validate(['user_ids' => ['required', 'array', 'min:1', 'max:100'], 'user_ids.*' => ['integer']]);
        $ids = User::query()->where('is_active', true)->whereKey($data['user_ids'])->pluck('id')->all();
        $conversation->members()->syncWithoutDetaching($ids);
        $conversation->touch();

        return new ConversationResource($this->reload($conversation, $request->user()));
    }

    /** DELETE /members/{user} — le créateur retire quelqu'un, ou chacun quitte le groupe. */
    public function removeMember(Request $request, Conversation $conversation, User $user): JsonResponse
    {
        $me = $request->user();
        abort_if($conversation->type === 'direct', 422, 'On ne quitte pas une conversation privée.');
        if ($user->id !== $me->id) {
            $this->authorize('manage', $conversation);
        } else {
            $this->authorize('view', $conversation);
        }
        $conversation->members()->detach($user->id);
        if (! $conversation->members()->exists()) {
            $conversation->delete();
        } else {
            $conversation->touch();
        }

        return response()->json(['message' => $user->id === $me->id ? 'Vous avez quitté la conversation.' : 'Personne retirée de la conversation.']);
    }

    /**
     * GET /messages — les 50 derniers ; `before_id` : plus anciens ; `after_id` : nouveaux (relève régulière) ;
     * `updated_since` : modifiés ou supprimés depuis. Du plus ancien au plus récent.
     */
    public function messages(Request $request, Conversation $conversation): AnonymousResourceCollection
    {
        $this->authorize('view', $conversation);
        $query = $conversation->messages()->withTrashed()->with(self::MESSAGE_WITH);

        if ($request->filled('after_id') || $request->filled('updated_since')) {
            $query->where(function (Builder $q) use ($request) {
                if ($request->filled('after_id')) {
                    $q->orWhere('id', '>', $request->integer('after_id'));
                }
                if ($request->filled('updated_since')) {
                    $q->orWhere('updated_at', '>', $request->date('updated_since'));
                }
            })->orderBy('id')->limit(300);
            $items = $query->get();
        } else {
            $items = $query->when($request->filled('before_id'), fn ($q) => $q->where('id', '<', $request->integer('before_id')))
                ->orderByDesc('id')->limit(50)->get()->reverse()->values();
        }

        return MessageResource::collection($items)->additional(['meta' => [
            'server_time' => now()->toIso8601String(),
            'reads' => $conversation->members()->get()->map(fn (User $u) => ['user_id' => $u->id, 'last_read_message_id' => $u->pivot->last_read_message_id])->values(),
        ]]);
    }

    /** POST /messages — `body` (ou `with_files` si seuls des fichiers suivent), `client_uuid`. */
    public function send(Request $request, Conversation $conversation): JsonResponse
    {
        $user = $request->user();
        $this->authorize('view', $conversation);
        $data = $request->validate([
            'client_uuid' => ['nullable', 'uuid'],
            'body' => ['nullable', 'string', 'max:10000'],
            'with_files' => ['sometimes', 'boolean'],
        ], [], ['body' => 'message']);
        abort_if(trim((string) ($data['body'] ?? '')) === '' && ! ($data['with_files'] ?? false), 422, 'Écrivez un message.');

        if (! empty($data['client_uuid']) && $existing = Message::withTrashed()->where('client_uuid', $data['client_uuid'])->first()) {
            return (new MessageResource($existing->load(self::MESSAGE_WITH)))->response()->setStatusCode(200);
        }

        $message = DB::transaction(function () use ($conversation, $data, $user) {
            $message = new Message(['client_uuid' => $data['client_uuid'] ?? null, 'body' => isset($data['body']) ? trim($data['body']) : null]);
            $message->conversation()->associate($conversation);
            $message->user_id = $user->id;
            $message->save();
            $conversation->forceFill(['last_message_at' => $message->created_at])->save();
            $conversation->members()->updateExistingPivot($user->id, ['last_read_message_id' => $message->id]);

            return $message;
        });

        return (new MessageResource($message->load(self::MESSAGE_WITH)))->response()->setStatusCode(201);
    }

    /** POST /read — tout est lu jusqu'au dernier message (ou `message_id`). */
    public function read(Request $request, Conversation $conversation): JsonResponse
    {
        $user = $request->user();
        $this->authorize('view', $conversation);
        $last = (int) $conversation->messages()->withTrashed()->max('id');
        $target = $request->filled('message_id') ? min($request->integer('message_id'), $last) : $last;
        $current = (int) $conversation->members()->whereKey($user->id)->first()?->pivot->last_read_message_id;
        if ($target > $current) {
            $conversation->members()->updateExistingPivot($user->id, ['last_read_message_id' => $target]);
        }

        return response()->json(['data' => ['count' => $this->unreadTotal($user)]]);
    }

    /** PATCH /api/v1/messages/{message} — son auteur corrige le texte. */
    public function updateMessage(Request $request, Message $message): MessageResource
    {
        $this->authorize('update', $message);
        $data = $request->validate(['body' => ['required', 'string', 'max:10000']], ['body.required' => 'Écrivez un message.']);
        if (trim($data['body']) !== $message->body) {
            $message->forceFill(['body' => trim($data['body']), 'edited_at' => now()])->save();
        }

        return new MessageResource($message->load(self::MESSAGE_WITH));
    }

    /** DELETE /api/v1/messages/{message} — son auteur ; le texte et les fichiers disparaissent pour tous. */
    public function destroyMessage(Message $message): JsonResponse
    {
        $this->authorize('delete', $message);
        DB::transaction(function () use ($message) {
            $message->documents()->get()->each->delete();
            $message->delete();
        });

        return response()->json(['message' => 'Message supprimé.']);
    }

    private function listQuery(User $user): Builder
    {
        return Conversation::query()->select('conversations.*')->of($user)->withUnread($user)
            ->with(['members:id,firstname,lastname,avatar_path,job_title', 'lastMessage.author:id,firstname,lastname', 'project:id,name', 'task:id,title']);
    }

    private function reload(Conversation $conversation, User $user): Conversation
    {
        return $this->listQuery($user)->whereKey($conversation->id)->firstOrFail();
    }

    private function unreadTotal(User $user): int
    {
        return Message::query()
            ->join('conversation_members as cm', fn ($j) => $j->on('cm.conversation_id', '=', 'messages.conversation_id')->where('cm.user_id', $user->id))
            ->whereNull('messages.deleted_at')
            ->where(fn ($q) => $q->whereNull('messages.user_id')->orWhere('messages.user_id', '!=', $user->id))
            ->whereRaw('messages.id > coalesce(cm.last_read_message_id, 0)')
            ->count();
    }
}
