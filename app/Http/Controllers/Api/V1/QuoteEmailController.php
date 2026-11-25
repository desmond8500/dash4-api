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
    /**
     * GET /api/v1/quotes/{id}/email-draft — destinataires proposés (contacts liés au devis d'abord, puis client
     * et contacts du client), objet et message. `document=proposal` : envoi de la proposition technique seule.
     */
    public function draft(Request $request, Quote $quote): JsonResponse
    {
        $proposal = $this->authorizeDocument($request, $quote, false);

        $draft = $proposal
            ? DocumentMailer::draft(
                $quote->client,
                $request->user(),
                "Proposition technique {$quote->number} — {$quote->title}",
                "Bonjour,\n\nVeuillez trouver ci-jointe notre proposition technique pour « {$quote->title} ».\n\n"
                    .'Nous restons à votre disposition pour en discuter.',
            )
            : DocumentMailer::draft(
                $quote->client,
                $request->user(),
                "Devis {$quote->number} — {$quote->title}",
                "Bonjour,\n\nVeuillez trouver ci-joint notre devis {$quote->number} pour « {$quote->title} ».\n\n"
                    .'Nous restons à votre disposition pour toute question.',
            );

        // Contacts liés au devis en premier, avec leur rôle
        $linked = $quote->linkedContacts()->with('contact.emails')->get()
            ->flatMap(fn ($l) => collect([$l->contact->email])->concat($l->contact->emails->pluck('address'))->filter()
                ->map(fn ($address) => ['email' => $address, 'name' => $l->contact->full_name, 'label' => $l->role ?: $l->contact->job_title]));
        $draft['suggestions'] = $linked->concat($draft['suggestions'])->unique('email')->values();

        return response()->json(['data' => $draft]);
    }

    /**
     * POST /api/v1/quotes/{id}/send — `to` (liste d'adresses), `cc`, `subject`, `message`, `signature_id`.
     * Devis (par défaut) : `attach_proposal` joint aussi la proposition technique ; un brouillon passe à « Envoyé ».
     * `document=proposal` : la proposition technique seule, sans changer le statut du devis.
     */
    public function send(Request $request, Quote $quote): QuoteResource
    {
        $proposal = $this->authorizeDocument($request, $quote, true);
        $data = DocumentMailer::validate($request, [
            'attach_proposal' => ['sometimes', 'boolean'],
            'document' => ['sometimes', 'in:quote,proposal'],
        ]);

        if ($proposal) {
            $files = [['name' => 'Proposition technique '.$quote->number.'.pdf', 'data' => QuotePdf::proposal($quote)->output()]];
            DocumentMailer::send($quote, $data, $files, $request->user(), "Proposition technique {$quote->number}");

            return new QuoteResource($quote->refresh()->load(QuoteEditing::FULL));
        }

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

    /**
     * Devis : voir les prix (export). Proposition technique : voir le devis, et les prix seulement si la
     * proposition les affiche. Envoyer demande en plus de pouvoir modifier le devis.
     *
     * @return bool true pour la proposition technique
     */
    private function authorizeDocument(Request $request, Quote $quote, bool $sending): bool
    {
        $proposal = $request->input('document') === 'proposal';
        if ($proposal) {
            $this->authorize('view', $quote);
            if ($quote->proposalOptions()['show_prices']) {
                $this->authorize('export', $quote);
            }
        } else {
            $this->authorize('export', $quote);
        }
        if ($sending) {
            $this->authorize('update', $quote);
        }

        return $proposal;
    }
}
