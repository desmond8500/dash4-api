<?php

namespace App\Models;

use App\Models\Concerns\HasAttachments;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/** Fournisseur (Dash3 : providers). */
#[Fillable(['client_uuid', 'name', 'contact_name', 'phone', 'email', 'address', 'website', 'description'])]
class Provider extends Model
{
    use HasAttachments, HasFactory, LogsActivity, SoftDeletes;

    public function articles(): HasMany
    {
        return $this->hasMany(Article::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['name', 'contact_name', 'phone', 'email'])
            ->logOnlyDirty()->dontLogEmptyChanges()->useLogName('stock');
    }
}
