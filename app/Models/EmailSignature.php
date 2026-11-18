<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Signature d'e-mail. Variables remplacées à l'envoi :
 * {nom} {prenom} {fonction} {telephone} {email} {entreprise} {site} {adresse}.
 */
#[Fillable(['name', 'body', 'is_default', 'image_width'])]
class EmailSignature extends Model
{
    public const VARIABLES = [
        '{nom}' => 'Nom complet de l\'expéditeur',
        '{prenom}' => 'Prénom',
        '{fonction}' => 'Fonction',
        '{telephone}' => 'Téléphone de l\'expéditeur',
        '{email}' => 'E-mail de l\'expéditeur',
        '{entreprise}' => 'Raison sociale',
        '{site}' => 'Site web de l\'entreprise',
        '{adresse}' => 'Adresse de l\'entreprise',
    ];

    protected $attributes = ['is_default' => false, 'image_width' => 160];

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'image_width' => 'integer'];
    }

    protected static function booted(): void
    {
        static::deleted(function (EmailSignature $signature) {
            if ($signature->image_path) {
                Storage::disk('public')->delete($signature->image_path);
            }
        });

        // Une seule signature par défaut par propriétaire (l'utilisateur, ou l'entreprise)
        static::saved(function (EmailSignature $signature) {
            if ($signature->is_default) {
                self::query()->where('user_id', $signature->user_id)->whereKeyNot($signature->id)
                    ->where('is_default', true)->update(['is_default' => false]);
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function imageUrl(): ?string
    {
        return $this->image_path ? Storage::disk('public')->url($this->image_path) : null;
    }

    /** Chemin du fichier, pour l'intégrer à l'e-mail (pièce jointe intégrée, pas de lien externe). */
    public function imageFile(): ?string
    {
        return $this->image_path && Storage::disk('public')->exists($this->image_path)
            ? Storage::disk('public')->path($this->image_path)
            : null;
    }

    public function isShared(): bool
    {
        return $this->user_id === null;
    }

    /** Les siennes et celles de l'entreprise. */
    public function scopeAvailableTo(Builder $query, User $user): Builder
    {
        return $query->where(fn ($q) => $q->where('user_id', $user->id)->orWhereNull('user_id'));
    }

    /** Signature proposée par défaut : la sienne par défaut, sinon celle de l'entreprise. */
    public static function defaultFor(User $user): ?self
    {
        return self::query()->where('user_id', $user->id)->where('is_default', true)->first()
            ?? self::query()->whereNull('user_id')->where('is_default', true)->first();
    }

    /** Texte final, variables remplacées par les informations de l'expéditeur et de l'entreprise. */
    public function render(User $sender): string
    {
        $company = Company::current();
        $address = trim(implode(', ', array_filter([$company->address, $company->city])));

        $text = strtr($this->body, [
            '{nom}' => $sender->full_name,
            '{prenom}' => (string) $sender->firstname,
            '{fonction}' => (string) $sender->job_title,
            '{telephone}' => (string) ($sender->phone ?: $company->phone),
            '{email}' => (string) $sender->email,
            '{entreprise}' => (string) $company->name,
            '{site}' => (string) $company->website,
            '{adresse}' => $address,
        ]);

        // Lignes restées vides (variable non renseignée) retirées
        return trim(preg_replace("/\n[ \t]*(?=\n)/", '', preg_replace('/[ \t]+$/m', '', $text)) ?? $text);
    }
}
