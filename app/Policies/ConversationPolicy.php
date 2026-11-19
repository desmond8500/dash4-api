<?php

namespace App\Policies;

use App\Models\Conversation;
use App\Models\User;

/** Seuls les membres voient une conversation et y écrivent ; son créateur gère le groupe. */
class ConversationPolicy
{
    public function view(User $user, Conversation $conversation): bool
    {
        return $conversation->hasMember($user);
    }

    public function manage(User $user, Conversation $conversation): bool
    {
        return $conversation->type === 'group' && $this->view($user, $conversation)
            && ($conversation->created_by === $user->id || $conversation->created_by === null || $user->hasRole('admin'));
    }
}
