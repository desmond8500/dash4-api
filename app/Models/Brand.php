<?php

namespace App\Models;

use App\Models\Concerns\HasAttachments;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name', 'description', 'website'])]
class Brand extends Model
{
    use HasAttachments, SoftDeletes;

    public function articles(): HasMany
    {
        return $this->hasMany(Article::class);
    }
}
