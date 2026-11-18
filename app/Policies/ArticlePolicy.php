<?php

namespace App\Policies;

use App\Models\Article;
use App\Models\User;

/** Stock : stock.view pour consulter (technicien compris, sans prix ni coût), stock.move pour les mouvements. */
class ArticlePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('stock.view');
    }

    public function view(User $user, Article $article): bool
    {
        return $user->can('stock.view');
    }

    public function create(User $user): bool
    {
        return $user->can('stock.create');
    }

    public function update(User $user, Article $article): bool
    {
        return $user->can('stock.update');
    }

    public function delete(User $user, Article $article): bool
    {
        return $user->can('stock.delete');
    }

    public function move(User $user, Article $article): bool
    {
        return $user->can('stock.move');
    }
}
