<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\BankAccount — réservé à bank.view */
class BankAccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'bank_name' => $this->bank_name,
            'holder' => $this->holder,
            'account_number' => $this->account_number,
            'iban' => $this->iban,
            'bic' => $this->bic,
            'is_default' => $this->is_default,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
