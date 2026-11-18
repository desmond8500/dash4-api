<?php

namespace App\Notifications;

use App\Models\Article;
use App\Models\User;
use Illuminate\Notifications\Notification;

/** Alerte de seuil : un article passe sous sa quantité minimale. */
class LowStockNotification extends Notification
{
    public function __construct(public Article $article) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $qty = fn (?float $v) => rtrim(rtrim(number_format((float) $v, 2, ',', ' '), '0'), ',');

        return [
            'kind' => 'stock.low',
            'title' => 'Stock bas : '.$this->article->designation,
            'body' => "Il reste {$qty($this->article->quantity)} {$this->article->unit} (seuil : {$qty($this->article->min_quantity)}).",
            'article_id' => $this->article->id,
        ];
    }

    /** Prévient ceux qui gèrent le stock (stock.move) et les administrateurs. */
    public static function send(Article $article): void
    {
        User::permission('stock.move')->where('is_active', true)->get()
            ->merge(User::role('admin')->where('is_active', true)->get())
            ->unique('id')
            ->each(fn (User $user) => $user->notify(new self($article)));
    }
}
