<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\QuoteLineCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Ligne de devis. Le coût et le coefficient demandent costs.view : un chef de projet
 * rédige les lignes, le chiffrage revient à ceux qui voient les coûts.
 */
class QuoteLineForm extends FormRequest
{
    public function rules(): array
    {
        $creating = $this->isMethod('post');

        return [
            'article_id' => ['sometimes', 'nullable', 'integer', Rule::exists('articles', 'id')->whereNull('deleted_at')],
            'designation' => [$creating ? 'required_without:article_id' : 'sometimes', 'string', 'max:255'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'comment' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'category' => ['sometimes', new Enum(QuoteLineCategory::class)],
            'unit' => ['sometimes', 'nullable', 'string', 'max:20'],
            'quantity' => ['sometimes', 'numeric', 'min:0', 'max:9999999'],
            'unit_cost' => ['sometimes', 'numeric', 'min:0', 'max:999999999999'],
            'coefficient' => ['sometimes', 'numeric', 'min:0', 'max:1000'],
            'position' => ['sometimes', 'integer', 'min:0'],
            // Déplacer la ligne dans une autre section du même devis
            'quote_section_id' => [
                'sometimes', 'integer',
                Rule::exists('quote_sections', 'id')->where('quote_id', $this->route('line')?->section?->quote_id),
            ],
        ];
    }

    public function attributes(): array
    {
        return [
            'designation' => 'désignation', 'reference' => 'référence', 'quantity' => 'quantité',
            'unit_cost' => 'coût unitaire', 'coefficient' => 'coefficient', 'category' => 'catégorie',
            'unit' => 'unité', 'quote_section_id' => 'section', 'article_id' => 'article',
        ];
    }
}
