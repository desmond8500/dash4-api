<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\MissingValue;

/** Champs téléphones / e-mails supplémentaires, renvoyés quand ils sont chargés. */
trait PhoneEmailFields
{
    protected function phoneEmailFields(): array
    {
        return [
            'phones' => $this->whenLoaded('phones', fn () => $this->phones->map(fn ($p) => [
                'id' => $p->id,
                'label' => $p->label,
                'number' => $p->number,
            ])->values(), new MissingValue),
            'emails' => $this->whenLoaded('emails', fn () => $this->emails->map(fn ($e) => [
                'id' => $e->id,
                'label' => $e->label,
                'address' => $e->address,
            ])->values(), new MissingValue),
        ];
    }
}
