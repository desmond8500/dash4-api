<?php

namespace App\Policies;

use App\Models\Message;
use App\Models\User;

/** Un message (et ses fichiers) n'est visible que des membres de sa conversation. */
class MessagePolicy
{
    public function view(User $user, Message $message): bool
    {
        return $message->conversation?->hasMember($user) ?? false;
    }

    public function update(User $user, Message $message): bool
    {
        return $message->user_id === $user->id && $this->view($user, $message);
    }

    public function delete(User $user, Message $message): bool
    {
        return $this->update($user, $message);
    }
}
