<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\TaxMode;
use App\Models\Project;
use App\Models\Quote;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/** En-tête d'un devis (création et modification). Le statut a sa propre route. */
class QuoteForm extends FormRequest
{
    public function rules(): array
    {
        $creating = $this->isMethod('post');
        $required = $creating ? 'required' : 'sometimes';

        return [
            'client_uuid' => $creating ? ['nullable', 'uuid'] : ['prohibited'],
            'client_id' => [$required, 'integer', Rule::exists('clients', 'id')->whereNull('deleted_at')],
            'project_id' => ['sometimes', 'nullable', 'integer', Rule::exists('projects', 'id')->whereNull('deleted_at'), $this->projectOfClient()],
            'title' => [$required, 'string', 'max:255'],
            'introduction' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'payment_terms' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'issued_at' => ['sometimes', 'date'],
            'valid_until' => ['sometimes', 'nullable', 'date', ...($this->filled('issued_at') ? ['after_or_equal:issued_at'] : [])],
            'tax_mode' => ['sometimes', new Enum(TaxMode::class)],
            'discount_rate' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'bank_account_id' => ['sometimes', 'nullable', 'integer', Rule::exists('bank_accounts', 'id')->whereNull('deleted_at')],
            'show_signature' => ['sometimes', 'boolean'],
            'is_favorite' => ['sometimes', 'boolean'],
            // Proposition technique : ce qui apparaît sur son PDF
            'proposal_options' => ['sometimes', 'array:'.implode(',', array_keys(Quote::PROPOSAL_DEFAULTS))],
            'proposal_options.client_label' => ['nullable', 'string', 'max:255'],
            'proposal_options.show_logo' => ['sometimes', 'boolean'],
            'proposal_options.show_client' => ['sometimes', 'boolean'],
            'proposal_options.show_project' => ['sometimes', 'boolean'],
            'proposal_options.show_introduction' => ['sometimes', 'boolean'],
            'proposal_options.show_lines' => ['sometimes', 'boolean'],
            'proposal_options.show_prices' => ['sometimes', 'boolean'],
            'proposal_options.show_footer' => ['sometimes', 'boolean'],
        ];
    }

    /** Le projet choisi doit appartenir au client du devis. */
    private function projectOfClient(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            $clientId = $this->input('client_id', $this->route('quote')?->client_id);
            if ($value && $clientId && Project::whereKey($value)->value('client_id') !== (int) $clientId) {
                $fail("Ce projet n'appartient pas au client du devis.");
            }
        };
    }

    public function attributes(): array
    {
        return [
            'client_id' => 'client', 'project_id' => 'projet', 'title' => 'objet', 'introduction' => 'introduction',
            'payment_terms' => 'modalités de paiement', 'issued_at' => 'date du devis', 'valid_until' => 'date de validité',
            'tax_mode' => 'taxe', 'discount_rate' => 'remise', 'bank_account_id' => 'compte bancaire',
        ];
    }
}
