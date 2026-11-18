<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/** L'entreprise (mono-société : une seule ligne, créée au premier accès). */
#[Fillable([
    'name', 'legal_form', 'address', 'city', 'country', 'phone', 'email', 'website',
    'ninea', 'rccm', 'share_capital', 'legal_mentions', 'terms',
])]
class Company extends Model
{
    use LogsActivity;

    protected $table = 'company';

    /** Documents où la signature et le cachet apparaissent, par défaut. */
    public const SIGNATURE_DOCUMENTS = [
        'quote' => 'Devis',
        'invoice' => 'Factures',
        'delivery_note' => 'Bons de livraison',
        'purchase_order' => 'Bons de commande',
        'report' => "Rapports d'intervention",
        'acceptance' => 'Procès-verbaux',
    ];

    protected function casts(): array
    {
        return ['signature_on' => 'array'];
    }

    public static function current(): self
    {
        if ($company = self::query()->oldest('id')->first()) {
            return $company;
        }

        $company = self::query()->forceCreate([
            'name' => config('app.name', 'Dash4'),
            'country' => 'Sénégal',
            'signature_on' => array_fill_keys(array_keys(self::SIGNATURE_DOCUMENTS), true),
        ]);
        // Fiche créée en coulisse : les réponses restent des lectures (200), pas des créations (201)
        $company->wasRecentlyCreated = false;

        return $company;
    }

    /** @return array<string, bool> */
    public function signatureSettings(): array
    {
        return array_merge(
            array_fill_keys(array_keys(self::SIGNATURE_DOCUMENTS), true),
            array_intersect_key($this->signature_on ?? [], self::SIGNATURE_DOCUMENTS),
        );
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([...$this->getFillable(), 'logo_path', 'signature_path', 'stamp_path', 'signature_on'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('company');
    }
}
