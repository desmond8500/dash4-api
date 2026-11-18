<?php

namespace App\Support;

use App\Enums\InvoiceScope;
use App\Enums\InvoiceStatus;
use App\Models\BankAccount;
use App\Models\Invoice;
use App\Models\Quote;
use App\Models\QuoteDeposit;
use App\Models\QuoteLine;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Factures issues d'un devis :
 *  - 100 % d'une partie (tout, matériel, matériel + accessoires, main-d'œuvre) : lignes du devis reprises ;
 *  - moins de 100 % : une ligne « Acompte de X % » ;
 *  - reliquat : tout le devis, moins ce qui a déjà été facturé (lignes « Déjà facturé »).
 * La remise du devis devient une ligne négative ; la taxe et ses taux sont repris du devis.
 */
final class InvoiceBuilder
{
    public static function fromQuote(Quote $quote, InvoiceScope $scope, float $percent, ?string $title, User $by): Invoice
    {
        return DB::transaction(function () use ($quote, $scope, $percent, $title, $by) {
            $invoice = self::draftFor($quote, $title ?: $scope->defaultTitle($percent), $by);
            $invoice->scope = $scope;
            $invoice->percent = $scope === InvoiceScope::Remainder ? 100 : $percent;
            $invoice->save();

            $lines = self::linesOf($quote, $scope);

            if ($scope === InvoiceScope::Remainder) {
                $rows = [...self::copy($lines), ...self::discountLine($quote, $lines), ...self::deductions($quote, $invoice)];
                $net = array_sum(array_map(fn ($r) => (int) round($r['unit_price'] * $r['quantity']), $rows));
                abort_if($net <= 0, 422, 'Tout le devis est déjà facturé : pas de reliquat à facturer.');
            } elseif ($percent >= 100) {
                $rows = [...self::copy($lines), ...self::discountLine($quote, $lines)];
            } else {
                $base = self::net($quote, $lines);
                $pct = rtrim(rtrim(number_format($percent, 2, ',', ''), '0'), ',');
                $what = $scope === InvoiceScope::All ? 'du devis' : 'sur '.mb_strtolower($scope->label()).' du devis';
                $rows = [[
                    'designation' => "Acompte de {$pct} % {$what} {$quote->number}",
                    'reference' => $quote->title,
                    'quantity' => 1,
                    'unit_price' => (int) round($base * $percent / 100),
                ]];
            }

            abort_if($rows === [], 422, "Le devis n'a aucune ligne pour cette partie (« {$scope->label()} »).");
            self::writeLines($invoice, $rows);

            return $invoice;
        });
    }

    /** Facture d'un acompte prévu sur le devis : le montant de l'acompte, taxe comprise. */
    public static function fromDeposit(QuoteDeposit $deposit, User $by): Invoice
    {
        $quote = $deposit->quote;

        return DB::transaction(function () use ($deposit, $quote, $by) {
            $invoice = self::draftFor($quote, "Facture d'acompte — {$deposit->label}", $by);
            $invoice->quote_deposit_id = $deposit->id;
            $invoice->scope = InvoiceScope::All;
            $invoice->percent = $quote->total > 0 ? round($deposit->amount / $quote->total * 100, 2) : 100;
            $invoice->save();

            // Montant hors taxes qui redonne le montant de l'acompte une fois la taxe appliquée
            $ht = match ($quote->tax_mode->value) {
                'vat' => $deposit->amount / (1 + $quote->vat_rate / 100),
                'brs' => $deposit->amount / (1 - $quote->brs_rate / 100),
                default => $deposit->amount,
            };
            self::writeLines($invoice, [[
                'designation' => "{$deposit->label} — devis {$quote->number}",
                'reference' => $deposit->description,
                'quantity' => 1,
                'unit_price' => (int) round($ht),
            ]]);

            return $invoice;
        });
    }

    /** Facture libre (sans devis). */
    public static function blank(array $data, User $by): Invoice
    {
        $invoice = new Invoice([
            'tax_mode' => Settings::defaultTax()->value,
            'bank_account_id' => BankAccount::query()->where('is_default', true)->value('id'),
            ...$data,
        ]);
        $invoice->scope = InvoiceScope::Custom;
        $invoice->vat_rate = (float) Settings::get('vat_rate');
        $invoice->brs_rate = (float) Settings::get('brs_rate');
        $invoice->created_by = $by->id;
        $invoice->save();

        return $invoice;
    }

    /** @param list<array<string, mixed>> $rows */
    public static function writeLines(Invoice $invoice, array $rows): void
    {
        foreach (array_values($rows) as $position => $row) {
            $invoice->lines()->create([...$row, 'position' => $position]);
        }
        $invoice->recalculate();
    }

    private static function draftFor(Quote $quote, string $title, User $by): Invoice
    {
        $invoice = new Invoice([
            'client_id' => $quote->client_id,
            'project_id' => $quote->project_id,
            'title' => $title,
            'introduction' => "Suivant notre devis {$quote->number} du {$quote->issued_at->format('d/m/Y')} : {$quote->title}.",
            'payment_terms' => $quote->payment_terms,
            'tax_mode' => $quote->tax_mode->value,
            'bank_account_id' => $quote->bank_account_id,
            'show_signature' => $quote->show_signature,
        ]);
        $invoice->quote_id = $quote->id;
        $invoice->vat_rate = $quote->vat_rate;
        $invoice->brs_rate = $quote->brs_rate;
        $invoice->created_by = $by->id;

        return $invoice;
    }

    /** @return Collection<int, QuoteLine> lignes des sections visibles, dans l'ordre du devis */
    private static function linesOf(Quote $quote, InvoiceScope $scope): Collection
    {
        $categories = $scope->categories();

        return QuoteLine::query()
            ->join('quote_sections', 'quote_sections.id', '=', 'quote_lines.quote_section_id')
            ->where('quote_sections.quote_id', $quote->id)
            ->where('quote_sections.is_visible', true)
            ->when($categories, fn ($q) => $q->whereIn('quote_lines.category', array_map(fn ($c) => $c->value, $categories)))
            ->where('quote_lines.line_total', '!=', 0)
            ->orderBy('quote_sections.position')->orderBy('quote_lines.position')->orderBy('quote_lines.id')
            ->get(['quote_lines.*']);
    }

    /** @return list<array<string, mixed>> */
    private static function copy(Collection $lines): array
    {
        return $lines->map(fn (QuoteLine $l) => [
            'designation' => $l->designation,
            'reference' => $l->reference,
            'unit' => $l->unit,
            'quantity' => $l->quantity,
            'unit_price' => $l->unit_price,
        ])->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private static function discountLine(Quote $quote, Collection $lines): array
    {
        if ($quote->discount_rate <= 0) {
            return [];
        }
        $discount = (int) round($lines->sum('line_total') * $quote->discount_rate / 100);
        $rate = rtrim(rtrim(number_format($quote->discount_rate, 2, ',', ''), '0'), ',');

        return $discount > 0 ? [['designation' => "Remise de {$rate} %", 'quantity' => 1, 'unit_price' => -$discount]] : [];
    }

    private static function net(Quote $quote, Collection $lines): int
    {
        $sum = (int) $lines->sum('line_total');

        return $sum - (int) round($sum * $quote->discount_rate / 100);
    }

    /** @return list<array<string, mixed>> une ligne négative par facture déjà faite sur ce devis */
    private static function deductions(Quote $quote, Invoice $current): array
    {
        return Invoice::query()
            ->where('quote_id', $quote->id)
            ->whereKeyNot($current->id)
            ->where('status', '!=', InvoiceStatus::Cancelled->value)
            ->orderBy('id')->get()
            ->filter(fn (Invoice $i) => $i->subtotal !== 0)
            ->map(fn (Invoice $i) => [
                'designation' => 'Déjà facturé : '.($i->number ?? 'brouillon').' — '.$i->title,
                'quantity' => 1,
                'unit_price' => -$i->subtotal,
            ])->values()->all();
    }
}
