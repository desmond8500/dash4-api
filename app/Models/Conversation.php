<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Conversation de la messagerie ; seuls ses membres la voient. */
#[Fillable(['title'])]
class Conversation extends Model
{
    protected function casts(): array
    {
        return ['last_message_at' => 'datetime'];
    }

    /** Une action hors ligne peut viser une conversation pas encore créée par son `client_uuid`. */
    public function resolveRouteBinding($value, $field = null): ?Model
    {
        return is_numeric($value)
            ? $this->whereKey($value)->first()
            : $this->whereIn('id', \Illuminate\Support\Facades\DB::table('conversation_uuids')->where('uuid', $value)->select('conversation_id'))->first();
    }

    public static function findByUuid(string $uuid): ?self
    {
        $id = \Illuminate\Support\Facades\DB::table('conversation_uuids')->where('uuid', $uuid)->value('conversation_id');

        return $id ? self::find($id) : null;
    }

    public function rememberUuid(?string $uuid): void
    {
        if ($uuid) {
            \Illuminate\Support\Facades\DB::table('conversation_uuids')->insertOrIgnore(['uuid' => $uuid, 'conversation_id' => $this->id]);
        }
    }

    public static function directKey(int $a, int $b): string
    {
        return min($a, $b).'-'.max($a, $b);
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'conversation_members')->withPivot('last_read_message_id')->withTimestamps();
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function lastMessage(): HasMany
    {
        return $this->hasMany(Message::class)->withTrashed()->latest('id')->limit(1);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function hasMember(User $user): bool
    {
        return $this->members()->whereKey($user->id)->exists();
    }

    public function scopeOf(Builder $query, User $user): Builder
    {
        return $query->whereHas('members', fn ($q) => $q->whereKey($user->id));
    }

    /** Ajoute le nombre de messages non lus par `$user` (`unread_count`). */
    public function scopeWithUnread(Builder $query, User $user): Builder
    {
        return $query->addSelect(['unread_count' => Message::query()->selectRaw('count(*)')
            ->whereColumn('messages.conversation_id', 'conversations.id')
            ->whereNull('messages.deleted_at')
            ->where(fn ($q) => $q->whereNull('messages.user_id')->orWhere('messages.user_id', '!=', $user->id))
            ->whereRaw('messages.id > coalesce((select cm.last_read_message_id from conversation_members cm where cm.conversation_id = conversations.id and cm.user_id = ?), 0)', [$user->id]),
        ]);
    }
}
