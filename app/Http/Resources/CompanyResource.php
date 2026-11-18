<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/** @mixin \App\Models\Company */
class CompanyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $canSignature = (bool) $request->user()?->can('signature.manage');

        return [
            'name' => $this->name,
            'legal_form' => $this->legal_form,
            'logo_url' => $this->logo_path ? Storage::disk('public')->url($this->logo_path) : null,
            'address' => $this->address,
            'city' => $this->city,
            'country' => $this->country,
            'phone' => $this->phone,
            'email' => $this->email,
            'website' => $this->website,
            'ninea' => $this->ninea,
            'rccm' => $this->rccm,
            'share_capital' => $this->share_capital,
            'legal_mentions' => $this->legal_mentions,
            'terms' => $this->terms,
            // Signature et cachet : seulement pour signature.manage (admin, comptable)
            'signature' => $this->when($canSignature, fn () => [
                'has_signature' => $this->signature_path !== null,
                'has_stamp' => $this->stamp_path !== null,
                'on' => $this->signatureSettings(),
            ]),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
