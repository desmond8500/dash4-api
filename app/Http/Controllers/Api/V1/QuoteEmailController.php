<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\QuoteStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\QuoteResource;
use App\Models\Quote;
use App\Support\DocumentMailer;
use App\Support\QuoteEditing;
use App\Support\QuotePdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Envoi d'un devis par e-mail (envoi immédiat : pas de file d'attente permanente sur l'hébergement).
 * Destinataires choisis par l'utilisateur ; l'envoi est gardé dans l'historique du devis.
 */
class QuoteEmailController extends Controller
{
    /** GET /api/v1/quotes/{id}/email-draft — destinataires proposés (client et contacts), objet et message. */
    public function draft(Request $request, Quote $quote): JsonResponse
    {
        $this->authorize('export', $quote);

        return response()->json(['data' => DocumentMailer::draft(
            $quote->client,
            $request->user(),
            "Devis {$quote->number} — {$quote->title}",
            "Bonjour,\n\nVeuillez trouver ci-joint notre devis {$quote->number} pour « {$quote->title} ».\n\n"
                .'Nous restons à votre disposition pour toute question.',
        )]);
    }

    /**
     * POST /api/v1/quotes/{id}/send — `to` (liste d'adresses), `cc`, `subject`, `message`, `signature_id`,
     * `attach_proposal` (joint aussi la proposition technique). Un brouillon passe à « Envoyé ».
     */
    public function send(Request $request, Quote $quote): QuoteResource
    {
        $this->authorize('export', $quote);
        $this->authorize('update', $quote);
        $data = DocumentMailer::validate($request, ['attach_proposal' => ['sometimes', 'boolean']]);

        $files = [['name' => $quote->number.'.pdf', 'data' => QuotePdf::quote($quote)->output()]];
        if ($request->boolean('attach_proposal')) {
            $files[] = ['name' => 'Proposition technique '.$quote->number.'.pdf', 'data' => QuotePdf::proposal($quote)->output()];
        }

        DocumentMailer::send($quote, $data, $files, $request->user(), "Devis {$quote->number}");

        if ($quote->status === QuoteStatus::Draft) {
            $quote->status = QuoteStatus::Sent;
        }
        $quote->sent_at ??= now();
        $quote->save();

        return new QuoteResource($quote->refresh()->load(QuoteEditing::FULL));
    }
}
