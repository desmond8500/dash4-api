<?php

namespace App\Notifications;

use App\Models\Comment;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/** On vous a mentionné (@nom) dans un commentaire. */
class CommentMentionNotification extends Notification
{
    public function __construct(public Comment $comment, public string $subjectType, public int $subjectId, public string $subjectLabel) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $author = $this->comment->author?->full_name ?? 'Quelqu\'un';

        return [
            'kind' => 'comment.mention',
            'comment_id' => $this->comment->id,
            // Fiche commentée, au format des adresses de l'API (projects, tasks, journals…)
            'subject_type' => $this->subjectType,
            'subject_id' => $this->subjectId,
            'title' => "{$author} vous a mentionné : {$this->subjectLabel}",
            'body' => Str::limit($this->comment->body, 140),
        ];
    }
}
