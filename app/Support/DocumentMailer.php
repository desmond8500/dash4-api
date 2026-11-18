<?php

namespace App\Support;

use App\Mail\QuoteMail;
use App\Models\Client;
use App\Models\EmailSignature;
use App\Models\QuoteEmail;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Testing\Fakes\MailFake;
use Throwable;

/**
 * Envoi d'un document (devis, facture) par e-mail : destinataires proposés, signatures,
 * validation, envoi immédiat avec les PDF joints, et trace dans l'historique.
 */
final class DocumentMailer
{
    /** Brouillon : adresses du client et de ses contacts, signatures de l'expéditeur. */
    public static function draft(?Client $client, User $user, string $subject, string $message): array
    {
        $client?->loadMissing(['emails', 'contacts.emails']);

        $suggestions = collect($client?->emails ?? [])
            ->map(fn ($e) => ['email' => $e->address, 'name' => $client->name, 'label' => $e->label])
            ->concat(collect($client?->contacts ?? [])->flatMap(fn ($c) => collect([$c->email])
                ->concat($c->emails->pluck('address'))
                ->filter()
                ->map(fn ($address) => ['email' => $address, 'name' => $c->full_name, 'label' => $c->job_title])))
            ->unique('email')
            ->values();

        return [
            'suggestions' => $suggestions,
            'subject' => $subject,
            // Sans signature : la signature choisie à l'envoi (signature_id) est ajoutée sous le message
            'message' => $message,
            'default_signature_id' => EmailSignature::defaultFor($user)?->id,
            'signatures' => EmailSignature::query()->availableTo($user)
                ->orderByRaw('user_id is null')->orderByDesc('is_default')->orderBy('name')->get()
                ->map(fn ($sig) => [
                    'id' => $sig->id, 'name' => $sig->name, 'shared' => $sig->isShared(), 'preview' => $sig->render($user),
                    'image_url' => $sig->imageUrl(), 'image_width' => $sig->image_width,
                ])
                ->values(),
        ];
    }

    /** @return array<string, mixed> données d'envoi validées (`to`, `cc`, `subject`, `message`, `signature_id`…) */
    public static function validate(Request $request, array $extra = []): array
    {
        return $request->validate([
            'to' => ['required', 'array', 'min:1', 'max:10'],
            'to.*' => ['email:filter'],
            'cc' => ['sometimes', 'array', 'max:10'],
            'cc.*' => ['email:filter'],
            'subject' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:10000'],
            'signature_id' => ['sometimes', 'nullable', 'integer'],
            ...$extra,
        ], ['to.required' => 'Indiquez au moins un destinataire.'], [
            'to' => 'destinataires', 'to.*' => 'destinataire', 'cc' => 'copie', 'cc.*' => 'copie', 'subject' => 'objet', 'message' => 'message',
        ]);
    }

    /**
     * Envoie et garde la trace (relation `emails()` du document).
     *
     * @param  list<array{name: string, data: string}>  $files
     */
    public static function send(Model $document, array $data, array $files, User $user, string $label): QuoteEmail
    {
        // MAIL_MAILER=log (réglage de départ) n'envoie rien : il écrit l'e-mail dans le journal.
        // On refuse plutôt que d'annoncer un envoi qui n'a pas eu lieu.
        abort_if(
            config('mail.default') === 'log' && ! Mail::getFacadeRoot() instanceof MailFake,
            503,
            "L'envoi des e-mails n'est pas configuré sur le serveur (MAIL_MAILER=log). "
                .'Renseignez les réglages MAIL_… du fichier .env (voir docs/DEPLOIEMENT.md), puis réessayez.',
        );

        $signature = isset($data['signature_id'])
            ? EmailSignature::query()->availableTo($user)->whereKey($data['signature_id'])->first()
            : null;
        abort_if(isset($data['signature_id']) && ! $signature, 422, 'Signature introuvable.');
        $body = $data['message'].($signature ? "\n\n-- \n".$signature->render($user) : '');

        try {
            Mail::to($data['to'])
                ->cc($data['cc'] ?? [])
                ->send(new QuoteMail(
                    $document, $data['subject'], $body, $files, $user->email, $user->full_name, $signature !== null,
                    $signature?->imageFile(), $signature?->image_width ?? 160,
                ));
        } catch (Throwable $e) {
            Log::warning("Envoi impossible : {$label}", ['error' => $e->getMessage()]);
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
        $document->emails()->save($email);

        activity($document->getMorphClass() === 'invoice' ? 'invoices' : 'quotes')
            ->performedOn($document)->causedBy($user)
            ->withProperties(['to' => $data['to'], 'cc' => $data['cc'] ?? []])
            ->log("{$label} envoyé par e-mail");

        return $email;
    }
}
