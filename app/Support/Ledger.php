<?php

namespace App\Support;

use App\Enums\ExpenseStatus;
use App\Enums\PurchaseStatus;
use App\Enums\TaxMode;
use App\Models\Expense;
use App\Models\InvoicePayment;
use App\Models\PurchaseLine;
use App\Models\PurchaseReception;
use App\Models\Transaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Journal des entrées et sorties d'argent, calculé à partir des modules :
 *  - entrées : paiements de factures reçus, transactions « entrée » ;
 *  - sorties : dépenses validées ou remboursées, réceptions d'achats (au prix d'achat, TVA comprise),
 *    transactions « sortie ».
 * Les versements dans une caisse sont des mouvements internes : ils n'y figurent pas.
 */
final class Ledger
{
    /** @return Collection<int, array{date: string, direction: string, amount: int, label: string, source: array{type: string, id: int, number: ?string}, category: ?string, project: ?array{id: int, name: string}}> */
    public static function entries(Carbon $from, Carbon $to, ?int $projectId = null): Collection
    {
        $range = [$from->toDateString(), $to->toDateString()];
        $project = fn ($p) => $p ? ['id' => $p->id, 'name' => $p->name] : null;

        $payments = InvoicePayment::query()->with(['invoice' => fn ($q) => $q->withTrashed()->with(['client:id,name', 'project:id,name'])])
            ->whereBetween('paid_at', $range)
            ->when($projectId, fn ($q) => $q->whereHas('invoice', fn ($i) => $i->where('project_id', $projectId)))
            ->get()
            ->map(fn (InvoicePayment $p) => [
                'date' => $p->paid_at->toDateString(),
                'direction' => 'in',
                'amount' => (int) $p->amount,
                'label' => trim('Paiement facture '.($p->invoice?->number ?? '').' — '.($p->invoice?->client?->name ?? ''), ' —'),
                'source' => ['type' => 'invoice', 'id' => (int) $p->invoice_id, 'number' => $p->invoice?->number],
                'category' => 'Ventes',
                'project' => $project($p->invoice?->project),
            ]);

        $expenses = Expense::query()->with(['category:id,name', 'project:id,name'])
            ->whereIn('status', ExpenseStatus::counted())
            ->whereBetween('spent_at', $range)
            ->when($projectId, fn ($q) => $q->where('project_id', $projectId))
            ->get()
            ->map(fn (Expense $e) => [
                'date' => $e->spent_at->toDateString(),
                'direction' => 'out',
                'amount' => $e->amount,
                'label' => $e->label,
                'source' => ['type' => 'expense', 'id' => $e->id, 'number' => null],
                'category' => $e->category?->name ?? 'Sans catégorie',
                'project' => $project($e->project),
            ]);

        $receptions = PurchaseReception::query()
            ->with(['purchase' => fn ($q) => $q->withTrashed()->with(['provider:id,name', 'project:id,name'])])
            ->whereBetween('received_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->whereHas('purchase', fn ($q) => $q->where('status', '!=', PurchaseStatus::Cancelled->value)
                ->when($projectId, fn ($p) => $p->where('project_id', $projectId)))
            ->get();
        $costs = PurchaseLine::query()->whereIn('id', $receptions->flatMap(fn ($r) => array_column($r->lines, 'purchase_line_id')))->pluck('unit_cost', 'id');
        $purchases = $receptions->map(function (PurchaseReception $r) use ($costs, $project) {
            $net = collect($r->lines)->sum(fn ($l) => (float) $l['quantity'] * (float) ($costs[$l['purchase_line_id']] ?? 0));
            $purchase = $r->purchase;
            $vat = $purchase?->tax_mode === TaxMode::Vat ? $purchase->vat_rate : 0;

            return [
                'date' => $r->received_at->toDateString(),
                'direction' => 'out',
                'amount' => (int) round($net * (1 + $vat / 100)),
                'label' => trim('Achat '.($purchase?->number ?? '').' — '.($purchase?->provider?->name ?? ''), ' —'),
                'source' => ['type' => 'purchase', 'id' => (int) $r->purchase_id, 'number' => $purchase?->number],
                'category' => 'Achats',
                'project' => $project($purchase?->project),
            ];
        })->filter(fn ($e) => $e['amount'] > 0);

        $transactions = Transaction::query()->with(['category:id,name', 'project:id,name'])
            ->whereBetween('transacted_at', $range)
            ->when($projectId, fn ($q) => $q->where('project_id', $projectId))
            ->get()
            ->map(fn (Transaction $t) => [
                'date' => $t->transacted_at->toDateString(),
                'direction' => $t->direction,
                'amount' => $t->amount,
                'label' => $t->label,
                'source' => ['type' => 'transaction', 'id' => $t->id, 'number' => null],
                'category' => $t->category?->name ?? ($t->direction === 'in' ? 'Autres entrées' : 'Autres sorties'),
                'project' => $project($t->project),
            ]);

        return $payments->concat($expenses)->concat($purchases)->concat($transactions)
            ->sortByDesc(fn ($e) => $e['date'].'|'.$e['source']['type'].'|'.str_pad((string) $e['source']['id'], 10, '0', STR_PAD_LEFT))
            ->values();
    }

    /** Totaux, par mois, par catégorie et par origine. */
    public static function summary(Collection $entries): array
    {
        $sum = fn (Collection $c, string $dir) => (int) $c->where('direction', $dir)->sum('amount');
        $in = $sum($entries, 'in');
        $out = $sum($entries, 'out');

        return [
            'in' => $in,
            'out' => $out,
            'net' => $in - $out,
            'by_month' => $entries->groupBy(fn ($e) => substr($e['date'], 0, 7))->sortKeys()
                ->map(fn ($c, $month) => ['month' => $month, 'in' => $sum($c, 'in'), 'out' => $sum($c, 'out')])->values(),
            'by_category' => $entries->groupBy(fn ($e) => $e['direction'].'|'.$e['category'])
                ->map(fn ($c, $key) => ['direction' => explode('|', $key)[0], 'category' => explode('|', $key, 2)[1], 'amount' => (int) $c->sum('amount')])
                ->sortByDesc('amount')->values(),
            'by_source' => $entries->groupBy(fn ($e) => $e['source']['type'])
                ->map(fn ($c, $type) => ['source' => $type, 'in' => $sum($c, 'in'), 'out' => $sum($c, 'out')])->values(),
        ];
    }
}
