<?php

namespace App\Support\Exports;

use App\Enums\InvoiceStatus;
use App\Models\CreditNote;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\Purchase;
use App\Models\Transaction;
use Illuminate\Support\Carbon;

/**
 * Exports comptables d'une période : tableaux CSV (factures, paiements, avoirs, dépenses, achats,
 * transactions) et journal des écritures au format FEC (comptes de config('dash4.accounting')).
 */
final class AccountingExport
{
    public const TYPES = [
        'invoices' => 'Factures',
        'payments' => 'Paiements reçus',
        'credit_notes' => 'Avoirs',
        'expenses' => 'Dépenses validées',
        'purchases' => 'Achats reçus',
        'transactions' => 'Transactions',
        'fec' => 'Journal des écritures (FEC)',
    ];

    public function __construct(private Carbon $from, private Carbon $to) {}

    /** @return array{0: list<string>, 1: iterable<list<scalar|null>>} en-têtes et lignes */
    public function table(string $type): array
    {
        $d = fn ($v) => $v ? Carbon::parse($v)->format('d/m/Y') : '';

        return match ($type) {
            'invoices' => [
                ['Numéro', 'Date', 'Échéance', 'Client', 'Projet', 'Titre', 'Statut', 'Taxe', 'Hors taxes', 'Taxe (montant)', 'Total', 'Payé', 'Avoirs', 'Reste'],
                $this->invoices()->with(['client:id,name', 'project:id,name'])->get()->map(fn (Invoice $i) => [
                    $i->number, $d($i->issued_at), $d($i->due_at), $i->client?->name, $i->project?->name, $i->title,
                    $i->status->label(), $i->tax_mode->label(), $i->subtotal, $i->tax_amount, $i->total, $i->paid_amount,
                    (int) $i->credited_amount, $i->remainingAmount(),
                ]),
            ],
            'payments' => [
                ['Date', 'Facture', 'Client', 'Mode', 'Référence', 'Montant'],
                $this->payments()->get()->map(fn (InvoicePayment $p) => [
                    $d($p->paid_at), $p->invoice?->number, $p->invoice?->client?->name, $p->method->label(), $p->reference, $p->amount,
                ]),
            ],
            'credit_notes' => [
                ['Numéro', 'Date', 'Facture', 'Client', 'Motif', 'Montant'],
                $this->creditNotes()->get()->map(fn (CreditNote $n) => [
                    $n->number, $d($n->issued_at), $n->invoice?->number, $n->client?->name, $n->reason, $n->amount,
                ]),
            ],
            'expenses' => [
                ['Date', 'Libellé', 'Catégorie', 'Projet', 'Fournisseur', 'Mode', 'Avancée', 'Payée', 'Montant'],
                $this->expenses()->get()->map(fn (Expense $e) => [
                    $d($e->spent_at), $e->label, $e->category?->name, $e->project?->name, $e->provider?->name,
                    $e->payment_method?->label(), $e->advanced ? 'oui' : 'non', $e->is_paid ? 'oui' : 'non', $e->amount,
                ]),
            ],
            'purchases' => [
                ['Numéro', 'Reçu le', 'Fournisseur', 'Titre', 'Hors taxes', 'TVA', 'Total'],
                $this->purchases()->get()->map(fn (Purchase $p) => [
                    $p->number, $d($p->received_at), $p->provider?->name, $p->title, $p->subtotal, $p->tax_amount, $p->total,
                ]),
            ],
            'transactions' => [
                ['Date', 'Sens', 'Libellé', 'Projet', 'Mode', 'Référence', 'Montant'],
                Transaction::query()->whereBetween('transacted_at', [$this->from->toDateString(), $this->to->toDateString()])
                    ->with('project:id,name')->orderBy('transacted_at')->get()->map(fn (Transaction $t) => [
                        $d($t->transacted_at), $t->direction === 'in' ? 'entrée' : 'sortie', $t->label, $t->project?->name,
                        $t->method?->label(), $t->reference, $t->amount,
                    ]),
            ],
            'fec' => [self::FEC_COLUMNS, $this->entries()],
        };
    }

    public const FEC_COLUMNS = ['JournalCode', 'JournalLib', 'EcritureNum', 'EcritureDate', 'CompteNum', 'CompteLib', 'CompAuxNum', 'CompAuxLib', 'PieceRef', 'PieceDate', 'EcritureLib', 'Debit', 'Credit', 'EcritureLet', 'DateLet', 'ValidDate', 'Montantdevise', 'Idevise'];

    /**
     * Écritures en partie double, chaque pièce équilibrée : ventes (factures, avoirs), trésorerie
     * (paiements reçus, transactions), achats reçus et dépenses validées.
     *
     * @return list<list<string>>
     */
    public function entries(): array
    {
        $a = config('dash4.accounting');
        $rows = [];
        $n = 0;
        $add = function (string $journal, string $journalLabel, Carbon|string|null $date, string $piece, string $label, array $lines) use (&$rows, &$n) {
            $n++;
            $date = Carbon::parse($date)->format('Ymd');
            foreach ($lines as [$account, $accountLabel, $auxNum, $auxLabel, $debit, $credit]) {
                if ((int) $debit === 0 && (int) $credit === 0) {
                    continue;
                }
                $rows[] = [$journal, $journalLabel, (string) $n, $date, $account, $accountLabel, $auxNum ?? '', $auxLabel ?? '',
                    $piece, $date, mb_substr($label, 0, 200), self::amount($debit), self::amount($credit), '', '', $date, '', ''];
            }
        };
        $cash = fn (?string $method) => match ($method) {
            'cash' => [$a['cash'], 'Caisse'],
            'wave', 'orange_money', 'free_money' => [$a['mobile_money'], 'Mobile money'],
            default => [$a['bank'], 'Banque'],
        };

        foreach ($this->invoices()->with('client:id,name')->get() as $i) {
            $aux = ['C'.$i->client_id, $i->client?->name];
            $lines = [[$a['clients'], 'Clients', ...$aux, $i->total, 0], [$a['sales'], 'Prestations de services', null, null, 0, $i->subtotal]];
            if ($i->tax_mode->value === 'vat') {
                $lines[] = [$a['vat_collected'], 'TVA facturée', null, null, 0, $i->tax_amount];
            } elseif ($i->tax_mode->value === 'brs') {
                $lines[] = [$a['brs'], 'BRS retenue par le client', null, null, $i->tax_amount, 0];
            }
            $add('VT', 'Ventes', $i->issued_at, (string) $i->number, "Facture {$i->number} — {$i->title}", $lines);
        }
        foreach ($this->creditNotes()->get() as $c) {
            $add('VT', 'Ventes', $c->issued_at, $c->number, "Avoir {$c->number} sur {$c->invoice?->number}", [
                [$a['sales'], 'Prestations de services', null, null, $c->amount, 0],
                [$a['clients'], 'Clients', 'C'.$c->client_id, $c->client?->name, 0, $c->amount],
            ]);
        }
        foreach ($this->payments()->get() as $p) {
            [$account, $accountLabel] = $cash($p->method->value);
            $add('BQ', 'Trésorerie', $p->paid_at, (string) $p->invoice?->number, "Règlement {$p->invoice?->number}", [
                [$account, $accountLabel, null, null, $p->amount, 0],
                [$a['clients'], 'Clients', 'C'.$p->invoice?->client_id, $p->invoice?->client?->name, 0, $p->amount],
            ]);
        }
        foreach ($this->purchases()->get() as $p) {
            $add('AC', 'Achats', $p->received_at, $p->number, "Achat {$p->number} — {$p->provider?->name}", [
                [$a['purchases'], 'Achats', null, null, $p->subtotal, 0],
                [$a['vat_deductible'], 'TVA déductible', null, null, $p->tax_amount, 0],
                [$a['suppliers'], 'Fournisseurs', 'F'.$p->provider_id, $p->provider?->name, 0, $p->total],
            ]);
        }
        foreach ($this->expenses()->get() as $e) {
            [$account, $accountLabel] = $cash($e->payment_method?->value);
            $add('OD', 'Dépenses', $e->spent_at, 'DEP-'.$e->id, $e->label, [
                [$a['expenses'], $e->category?->name ?? 'Autres charges externes', null, null, $e->amount, 0],
                [$account, $accountLabel, null, null, 0, $e->amount],
            ]);
        }
        foreach (Transaction::query()->whereBetween('transacted_at', [$this->from->toDateString(), $this->to->toDateString()])->orderBy('transacted_at')->get() as $t) {
            [$account, $accountLabel] = $cash($t->method?->value);
            $in = $t->direction === 'in';
            $add('BQ', 'Trésorerie', $t->transacted_at, 'TR-'.$t->id, $t->label, [
                [$account, $accountLabel, null, null, $in ? $t->amount : 0, $in ? 0 : $t->amount],
                $in ? [$a['other_income'], 'Autres produits', null, null, 0, $t->amount] : [$a['other_charges'], 'Autres charges', null, null, $t->amount, 0],
            ]);
        }

        usort($rows, fn ($x, $y) => [$x[3], (int) $x[2]] <=> [$y[3], (int) $y[2]]);

        return $rows;
    }

    private static function amount(int|float|string $v): string
    {
        return number_format((float) $v, 2, ',', '');
    }

    private function invoices(): \Illuminate\Database\Eloquent\Builder
    {
        return Invoice::query()->whereNotIn('status', [InvoiceStatus::Draft->value, InvoiceStatus::Cancelled->value])
            ->whereBetween('issued_at', [$this->from->toDateString(), $this->to->toDateString()])->orderBy('issued_at')->orderBy('id');
    }

    private function payments(): \Illuminate\Database\Eloquent\Builder
    {
        return InvoicePayment::query()->whereBetween('paid_at', [$this->from->toDateString(), $this->to->toDateString()])
            ->with('invoice.client:id,name')->orderBy('paid_at');
    }

    private function creditNotes(): \Illuminate\Database\Eloquent\Builder
    {
        return CreditNote::query()->whereBetween('issued_at', [$this->from->toDateString(), $this->to->toDateString()])
            ->with(['invoice:id,number', 'client:id,name'])->orderBy('issued_at');
    }

    private function expenses(): \Illuminate\Database\Eloquent\Builder
    {
        return Expense::query()->where('status', 'approved')
            ->whereBetween('spent_at', [$this->from->toDateString(), $this->to->toDateString()])
            ->with(['category:id,name', 'project:id,name', 'provider:id,name'])->orderBy('spent_at');
    }

    private function purchases(): \Illuminate\Database\Eloquent\Builder
    {
        return Purchase::query()->whereIn('status', ['received', 'partially_received'])
            ->whereBetween('received_at', [$this->from->copy()->startOfDay(), $this->to->copy()->endOfDay()])
            ->with('provider:id,name')->orderBy('received_at');
    }
}
