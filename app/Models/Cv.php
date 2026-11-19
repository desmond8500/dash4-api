<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** CV d'un utilisateur ; visible par son auteur, et par les administrateurs s'il le partage. */
#[Fillable(['name', 'title', 'fullname', 'summary', 'phone', 'email', 'address', 'website', 'accent_color', 'font', 'layout', 'show_photo', 'shared'])]
class Cv extends Model
{
    use SoftDeletes;

    public const SECTION_TYPES = [
        'experience' => 'Expériences',
        'education' => 'Formations',
        'skills' => 'Compétences',
        'certifications' => 'Certifications',
        'languages' => 'Langues',
        'projects' => 'Projets réalisés',
        'free' => 'Autre',
    ];

    protected $attributes = ['accent_color' => '#1690b7', 'font' => 'sans', 'layout' => 'two_columns', 'show_photo' => true, 'shared' => false];

    protected function casts(): array
    {
        return ['show_photo' => 'boolean', 'shared' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sections(): HasMany
    {
        return $this->hasMany(CvSection::class)->orderBy('position')->orderBy('id');
    }
}
