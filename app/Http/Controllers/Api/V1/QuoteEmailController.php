<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\QuoteStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\QuoteResource;
use App\Mail\QuoteMail;
use App\Models\EmailSignature;
use App\Models\Quote;
use App\Models\QuoteEmail;
use App\Support\QuoteEditing;
use App\Support\QuotePdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

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
        $quote->load(['client.emails', 'client.contacts.emails']);

        $suggestions = collect($quote->client?->emails ?? [])
            ->map(fn ($e) => ['email' => $e->address, 'name' => $quote->client->name, 'label' => $e->label])
            ->concat(collect($quote->client?->contacts ?? [])->flatMap(fn ($c) => collect([$c->email])
                ->concat($c->emails->pluck('address'))
                ->filter()
                ->map(fn ($address) => ['email' => $address, 'name' => $c->full_name, 'label' => $c->job_title])))
            ->unique('email')
            ->values();

        return response()->json(['data' => [
            'suggestions' => $suggestions,
            'subject' => "Devis {$quote->number} — {$quote->title}",
            // Sans signature : la signature choisie à l'envoi (signature_id) est ajoutée sous le message
            'message' => "Bonjour,\n\nVeuillez trouver ci-joint notre devis {$quote->number} pour « {$quote->title} ».\n\n"
                .'Nous restons à votre disposition pour toute question.',
            'default_signature_id' => EmailSignature::defaultFor($request->user())?->id,
            'signatures' => EmailSignature::query()->availableTo($request->user())
                ->orderByRaw('user_id is null')->orderByDesc('is_default')->orderBy('name')->get()
                ->map(fn ($sig) => ['id' => $sig->id, 'name' => $sig->name, 'shared' => $sig->isShared(), 'preview' => $sig->render($request->user())])
                ->values(),
        ]]);
    }

    /**
     * POST /api/v1/quotes/{id}/send — `to` (liste d'adresses), `cc`, `subject`, `message`,
     * `attach_proposal` (joint aussi la proposition technique). Un brouillon passe à « Envoyé ».
     */
    public function send(Request $request, Quote $quote): QuoteResource
    {
        $this->authorize('export', $quote);
        $this->authorize('update', $quote);
        $data = $request->validate([
            'to' => ['required', 'array', 'min:1', 'max:10'],
            'to.*' => ['email:filter'],
            'cc' => ['sometimes', 'array', 'max:10'],
            'cc.*' => ['email:filter'],
            'subject' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:10000'],
            'attach_proposal' => ['sometimes', 'boolean'],
            'signature_id' => ['sometimes', 'nullable', 'integer'],
        ], ['to.required' => 'Indiquez au moins un destinataire.'], [
            'to' => 'destinataires', 'to.*' => 'destinataire', 'cc' => 'copie', 'cc.*' => 'copie', 'subject' => 'objet', 'message' => 'message',
        ]);

        // MAIL_MAILER=log (réglage de départ) n'envoie rien : il écrit l'e-mail dans le journal.
        // On refuse plutôt que d'annoncer un envoi qui n'a pas eu lieu.
        abort_if(
            in_array(config('mail.default'), ['log', 'array'], true) && ! Mail::getFacadeRoot() instanceof \Illuminate\Support\Testing\Fakes\MailFake,
            503,
            "L'envoi des e-mails n'est pas configuré sur le serveur (MAIL_MAILER=".config('mail.default').'). '
                .'Renseignez les réglages MAIL_… du fichier .env (voir docs/DEPLOIEMENT.md), puis réessayez.',
        );

        $files = [['name' => $quote->number.'.pdf', 'data' => QuotePdf::quote($quote)->output()]];
        if ($request->boolean('attach_proposal')) {
            $files[] = ['name' => 'Proposition technique '.$quote->number.'.pdf', 'data' => QuotePdf::proposal($quote)->output()];
        }

        $user = $request->user();
        $signature = isset($data['signature_id'])
            ? EmailSignature::query()->availableTo($user)->whereKey($data['signature_id'])->first()
            : null;
        abort_if(isset($data['signature_id']) && ! $signature, 422, 'Signature introuvable.');
        $body = $data['message'].($signature ? "\n\n-- \n".$signature->render($user) : '');

        try {
            Mail::to($data['to'])
                ->cc($data['cc'] ?? [])
                ->send(new QuoteMail($quote, $data['subject'], $body, $files, $user->email, $user->full_name, $signature !== null));
        } catch (Throwable $e) {
            Log::warning('Envoi du devis impossible', ['quote' => $quote->number, 'error' => $e->getMessage()]);
            abort(502, "L'e-mail n'a pas pu être envoyé. Vérifiez les réglages d'envoi des e-mails du serveur (MAIL_…), puis réessayez.");
        }

        $email = new QuoteEmail([
            'to' => $data['to'],
            'cc' => $data['cc'] ?? [],
            'subject' => $data['subject'],
            'message' => $body,
            'attachments' => array_column($files, 'name'),
            'sent_at' => now(),
        ]);
        $email->sent_by = $user->id;
        $email->email_signature_id = $signature?->id;
        $quote->emails()->save($email);

        if ($quote->status === QuoteStatus::Draft) {
            $quote->status = QuoteStatus::Sent;
        }
        $quote->sent_at ??= now();
        $quote->save();
        activity('quotes')->performedOn($quote)->causedBy($user)
            ->withProperties(['to' => $data['to'], 'cc' => $data['cc'] ?? []])->log('Devis envoyé par e-mail');

        return new QuoteResource($quote->refresh()->load(QuoteEditing::FULL));
    }
}
