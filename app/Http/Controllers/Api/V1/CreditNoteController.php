<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Support\DocumentNumber;
use App\Support\QuotePdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use App\Support\PdfName;

/** Avoirs : annulent tout ou partie d'une facture émise (invoices.issue). */
class CreditNoteController extends Controller
{
    /** POST /api/v1/invoices/{invoice}/credit-notes — `amount` (tout le reste par défaut), `reason`, `issued_at`. */
    public function store(Request $request, Invoice $invoice): JsonResponse
    {
        $this->authorize('issue', $invoice);
        abort_if($invoice->isDraft() || $invoice->status->value === 'cancelled', 422, 'Un avoir ne se fait que sur une facture émise.');
        $max = $invoice->creditableAmount();
        abort_if($max <= 0, 422, 'Cette facture est déjà entièrement annulée par des avoirs.');
        $data = $request->validate([
            'amount' => ['sometimes', 'integer', 'min:1', "max:{$max}"],
            'reason' => ['required', 'string', 'max:2000'],
            'issued_at' => ['sometimes', 'date'],
        ], ['amount.max' => 'L\'avoir ne peut pas dépasser '.number_format($max, 0, ',', ' ').' F CFA.'], ['amount' => 'montant', 'reason' => 'motif']);

        $note = DB::transaction(function () use ($invoice, $data, $max, $request) {
            $issued = isset($data['issued_at']) ? \Illuminate\Support\Carbon::parse($data['issued_at']) : now();
            $note = new CreditNote(['amount' => $data['amount'] ?? $max, 'reason' => $data['reason'], 'issued_at' => $issued]);
            $note->number = DocumentNumber::next('credit_note', $issued);
            $note->client_id = $invoice->client_id;
            $note->created_by = $request->user()->id;
            $invoice->creditNotes()->save($note);
            $invoice->refreshPayments();
            activity('invoices')->performedOn($invoice)->causedBy($request->user())
                ->withProperties(['credit_note' => $note->number, 'amount' => $note->amount])->log("Avoir {$note->number}");

            return $note;
        });

        return response()->json([
            'data' => ['id' => $note->id, 'number' => $note->number, 'amount' => $note->amount],
            'message' => "Avoir {$note->number} créé.",
        ], 201);
    }

    public function pdf(Request $request, CreditNote $creditNote): Response
    {
        $this->authorize('view', $creditNote->invoice);
        $pdf = QuotePdf::creditNote($creditNote);

        $name = PdfName::creditNote($creditNote->loadMissing(['client', 'invoice.client']));

        return $request->boolean('inline') ? $pdf->stream($name) : $pdf->download($name);
    }
}
