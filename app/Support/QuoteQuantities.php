<?php

namespace App\Support;

use App\Enums\QuoteLineCategory;
use App\Models\Quote;
use App\Models\QuoteLine;
use App\Models\QuoteSection;
use Illuminate\Support\Collection;

/**
 * Quantitatif d'un devis : désignations et quantités, sans prix ni coût, sans les lignes
 * « Main-d'œuvre / forfait ». Toutes les sections visibles, ou une seule section.
 * Le récapitulatif additionne les lignes identiques (même article, sinon même désignation,
 * référence et unité) de toutes les sections.
 */
final class QuoteQuantities
{
    /** @var Collection<int, QuoteSection> */
    public readonly Collection $sections;

    public function __construct(public readonly Quote $quote, public readonly ?QuoteSection $section = null)
    {
        $sections = $section
            ? collect([$section->loadMissing('lines')])
            : $quote->sections()->where('is_visible', true)->orderBy('position')->orderBy('id')->with('lines')->get();

        $this->sections = $sections
            ->each(fn (QuoteSection $s) => $s->setRelation('lines', $s->lines->reject(fn (QuoteLine $l) => self::isFlatRate($l))->values()))
            ->filter(fn (QuoteSection $s) => $s->lines->isNotEmpty())
            ->values();
    }

    /** Ligne de forfait ou de main-d'œuvre : absente du quantitatif. */
    public static function isFlatRate(QuoteLine $line): bool
    {
        return $line->category === QuoteLineCategory::Service;
    }

    /**
     * Lignes identiques additionnées, par catégorie (ordre de l'énumération) puis dans l'ordre d'apparition.
     *
     * @return list<array{designation: string, reference: ?string, category: string, unit: ?string, quantity: float}>
     */
    public function summary(): array
    {
        $order = array_flip(array_map(fn ($c) => $c->value, QuoteLineCategory::cases()));
        $rows = [];
        foreach ($this->sections as $section) {
            foreach ($section->lines as $line) {
                $key = $line->article_id
                    ? 'a'.$line->article_id.'|'.mb_strtolower((string) $line->unit)
                    : 'd'.mb_strtolower(trim((string) $line->designation)).'|'.mb_strtolower(trim((string) $line->reference)).'|'.mb_strtolower((string) $line->unit);
                if (isset($rows[$key])) {
                    $rows[$key]['quantity'] += (float) $line->quantity;
                } else {
                    $rows[$key] = [...self::row($line), 'rank' => count($rows), 'order' => $order[$line->category->value] ?? 99];
                }
            }
        }
        $rows = array_values($rows);
        usort($rows, fn ($a, $b) => [$a['order'], $a['rank']] <=> [$b['order'], $b['rank']]);

        return array_map(fn ($r) => array_diff_key($r, ['rank' => 1, 'order' => 1]), $rows);
    }

    /** @return array{designation: string, reference: ?string, category: string, unit: ?string, quantity: float} */
    public static function row(QuoteLine $line): array
    {
        return [
            'designation' => (string) $line->designation,
            'reference' => $line->reference,
            'category' => $line->category->label(),
            'unit' => $line->unit,
            'quantity' => (float) $line->quantity,
        ];
    }

    /**
     * Tableau pour le CSV : une ligne par ligne de devis (avec sa section), puis, s'il y a plusieurs sections,
     * le récapitulatif après une ligne vide.
     *
     * @return array{0: list<string>, 1: list<list<string|float|null>>}
     */
    public function table(): array
    {
        $headers = ['Section', 'Catégorie', 'Désignation', 'Référence', 'Unité', 'Quantité'];
        $rows = [];
        foreach ($this->sections as $section) {
            foreach ($section->lines as $line) {
                $r = self::row($line);
                $rows[] = [$section->title, $r['category'], $r['designation'], $r['reference'], $r['unit'], self::number($r['quantity'])];
            }
        }
        if ($this->sections->count() > 1) {
            $rows[] = [];
            foreach ($this->summary() as $r) {
                $rows[] = ['Récapitulatif', $r['category'], $r['designation'], $r['reference'], $r['unit'], self::number($r['quantity'])];
            }
        }

        return [$headers, $rows];
    }

    /** Nom du fichier, sans extension. */
    public function filename(): string
    {
        $name = 'Quantitatif '.$this->quote->number;
        if ($this->section) {
            $name .= ' - '.preg_replace('/[\\\\\/:*?"<>|]+/', ' ', (string) $this->section->title);
        }

        return trim(preg_replace('/\s+/', ' ', $name));
    }

    /** Quantité au format français pour un tableur (virgule décimale, sans zéros inutiles). */
    public static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, ',', ''), '0'), ',');
    }
}
