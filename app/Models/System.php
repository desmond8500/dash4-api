<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Catalogue des systèmes (vidéosurveillance, alarme, détection incendie, contrôle d'accès…). */
#[Fillable(['name', 'icon', 'description'])]
class System extends Model
{
    use SoftDeletes;
}
