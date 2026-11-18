<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\QuoteResource;
use App\Models\Document;
use App\Models\Quote;
use App\Models\QuoteDeposit;
use App\Models\QuoteExpense;
use App\Support\QuoteEditing;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Acomptes (prices.view) et dépenses liées (costs.view) d'un devis.
 * Possibles quel que soit le statut : un acompte se paie souvent après l'acceptation.
 */
class QuoteMoneyController extends Controller
{
    /** POST /api/v1/quotes/{id}/deposits — `amount` en F CFA, ou `percent` du total du devis. */
    public function storeDeposit(Request $request, Quote $quote): QuoteResource
    {
        $this->authorize('update', $quote);
        $this->need($request, 'prices.view');
        $data = $this->validateDeposit($request, true);

        $amount = $data['amount'] ?? (int) round($quote->total * $data['percent'] / 100);
        unset($data['percent']);
        $deposit = new QuoteDeposit([...$data, 'amount' => $amount]);
        $deposit->label ??= 'Acompte';
        $deposit->created_by = $request->user()->id;
        $quote->deposits()->save($deposit);

        return $this->respond($quote);
    }

    public function updateDeposit(Request $request, QuoteDeposit $deposit): QuoteResource
    {
        $quote = $deposit->quote;
        $this->authorize('update', $quote);
        $this->need($request, 'prices.view');
        $data = $this->validateDeposit($request, false);

        if (isset($data['percent'])) {
            $data['amount'] = (int) round($quote->total * $data['percent'] / 100);
            unset($data['percent']);
        }
        $deposit->update($data);

        return $this->respond($quote);
    }

    public function destroyDeposit(Request $request, QuoteDeposit $deposit): QuoteResource
    {
        $quote = $deposit->quote;
        $this->authorize('update', $quote);
        $this->need($request, 'prices.view');
        $deposit->delete();

        return $this->respond($quote);
    }

    /** POST /api/v1/quotes/{id}/expenses — `file` facultatif (reçu, facture fournisseur), rangé dans les documents. */
    public function storeExpense(Request $request, Quote $quote): QuoteResource
    {
        $this->authorize('update', $quote);
        $this->need($request, 'costs.view');
        $data = $this->validateExpense($request, true);

        DB::transaction(function () use ($quote, $data, $request) {
            $expense = new QuoteExpense($data);
            $expense->created_by = $request->user()->id;
            if ($request->hasFile('file')) {
                $expense->document_id = $this->storeReceipt($request, $quote, $data['label'])->id;
            }
            $quote->expenses()->save($expense);
        });

        return $this->respond($quote);
    }

    public function updateExpense(Request $request, QuoteExpense $expense): QuoteResource
    {
        $quote = $expense->quote;
        $this->authorize('update', $quote);
        $this->need($request, 'costs.view');
        $data = $this->validateExpense($request, false);

        $expense->fill($data);
        if ($request->hasFile('file')) {
            $expense->document_id = $this->storeReceipt($request, $quote, $expense->label)->id;
        }
        $expense->save();

        return $this->respond($quote);
    }

    public function destroyExpense(Request $request, QuoteExpense $expense): QuoteResource
    {
        $quote = $expense->quote;
        $this->authorize('update', $quote);
        $this->need($request, 'costs.view');
        $expense->delete();

        return $this->respond($quote);
    }

    private function storeReceipt(Request $request, Quote $quote, string $label): Document
    {
        $file = $request->file('file');
        $document = $quote->documents()->make([
            'name' => 'Dépense — '.$label.'.'.strtolower($file->getClientOriginalExtension()),
            'path' => $file->store("documents/quotes/{$quote->id}", Document::DISK),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
        ]);
        $document->created_by = $request->user()->id;
        $document->save();

        return $document;
    }

    private function validateDeposit(Request $request, bool $creating): array
    {
        return $request->validate([
            'label' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'amount' => [$creating ? 'required_without:percent' : 'sometimes', 'integer', 'min:1'],
            'percent' => ['sometimes', 'numeric', 'min:0.01', 'max:100', 'prohibits:amount'],
            'due_date' => ['sometimes', 'nullable', 'date'],
            'is_paid' => ['sometimes', 'boolean'],
            'paid_at' => ['sometimes', 'nullable', 'date'],
            'show_on_pdf' => ['sometimes', 'boolean'],
        ], ['amount.required_without' => 'Indiquez le montant ou le pourcentage de l\'acompte.'], [
            'label' => 'libellé', 'amount' => 'montant', 'percent' => 'pourcentage', 'due_date' => 'échéance', 'paid_at' => 'date de paiement',
        ]);
    }

    private function validateExpense(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return array_diff_key($request->validate([
            'label' => [$required, 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'amount' => [$required, 'numeric', 'min:0'],
            'spent_at' => [$creating ? 'sometimes' : 'sometimes', 'date'],
            'is_paid' => ['sometimes', 'boolean'],
            'file' => ['sometimes', 'file', 'max:20480', 'extensions:pdf,jpg,jpeg,png,webp,heic'],
        ], [], ['label' => 'libellé', 'amount' => 'montant', 'spent_at' => 'date', 'file' => 'justificatif']), ['file' => true])
            + ($creating ? ['spent_at' => $request->input('spent_at', now()->toDateString())] : []);
    }

    private function respond(Quote $quote): QuoteResource
    {
        return new QuoteResource($quote->refresh()->load(QuoteEditing::FULL));
    }

    private function need(Request $request, string $permission): void
    {
        abort_unless($request->user()->can($permission), 403, "Vous n'avez pas le droit de faire cette action.");
    }
}
