<?php

namespace App\Models\Concerns;

use App\Models\Email;
use App\Models\Phone;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** Téléphones et e-mails supplémentaires d'une fiche. */
trait HasPhonesAndEmails
{
    public function phones(): MorphMany
    {
        return $this->morphMany(Phone::class, 'phoneable')->orderBy('id');
    }

    public function emails(): MorphMany
    {
        return $this->morphMany(Email::class, 'emailable')->orderBy('id');
    }

    /**
     * Remplace les téléphones et e-mails par ceux envoyés.
     * Une clé absente (null) laisse la liste existante inchangée.
     *
     * @param  list<array{label?: ?string, number: string}>|null  $phones
     * @param  list<array{label?: ?string, address: string}>|null  $emails
     */
    public function syncPhonesAndEmails(?array $phones, ?array $emails): void
    {
        if ($phones !== null) {
            $this->phones()->delete();
            $this->phones()->createMany(array_map(fn (array $p) => [
                'label' => $p['label'] ?? null,
                'number' => $p['number'],
            ], $phones));
        }

        if ($emails !== null) {
            $this->emails()->delete();
            $this->emails()->createMany(array_map(fn (array $e) => [
                'label' => $e['label'] ?? null,
                'address' => $e['address'],
            ], $emails));
        }
    }
}
