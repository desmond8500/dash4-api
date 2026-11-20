<?php

use App\Models\Client;
use App\Models\Contact;
use App\Models\DeliveryNote;
use App\Models\InboundEmail;
use App\Models\Invoice;
use App\Models\Quote;
use App\Models\Task;
use App\Support\Inbox\InboxFetcher;
use App\Support\Inbox\MailboxSource;
use App\Support\Inbox\MimeParser;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/** Fausse boîte mail : messages bruts, et ceux marqués lus. */
class FakeMailbox implements MailboxSource
{
    public array $seen = [];

    public function __construct(public array $messages) {}

    public function unread(int $limit): iterable
    {
        foreach (array_slice($this->messages, 0, $limit, true) as $id => $raw) {
            yield $id => $raw;
        }
    }

    public function markSeen(int|string $id): void
    {
        $this->seen[] = $id;
    }

    public function close(): void {}
}

function rawEmail(string $from, string $subject, string $id, bool $withPdf = false): string
{
    $pdf = base64_encode('%PDF-1.4 plan');
    $body = $withPdf
        ? "Content-Type: multipart/mixed; boundary=\"B\"\r\n\r\n--B\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nLa caméra du hall ne marche plus.\r\n--B\r\nContent-Type: application/pdf\r\nContent-Disposition: attachment; filename=\"plan.pdf\"\r\nContent-Transfer-Encoding: base64\r\n\r\n{$pdf}\r\n--B--\r\n"
        : "Content-Type: text/plain; charset=utf-8\r\n\r\nBonjour, merci de nous rappeler.\r\n";

    return "From: {$from}\r\nTo: demandes@yonkou.info\r\nSubject: {$subject}\r\nMessage-ID: <{$id}>\r\nDate: Wed, 18 Nov 2026 10:15:00 +0000\r\n{$body}";
}

it('lit un e-mail MIME : en-têtes encodés, texte en ISO-8859-1, pièce jointe au nom encodé', function () {
    $raw = "From: =?UTF-8?B?QW1hZG91IERpw6huZQ==?= <Amadou@Teranga.SN>\r\nSubject: =?ISO-8859-1?Q?Cam=E9ra_en_panne?=\r\nMessage-ID: <abc@mail.sn>\r\n"
        ."Content-Type: multipart/mixed; boundary=\"X\"\r\n\r\n--X\r\nContent-Type: text/plain; charset=iso-8859-1\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\nLa cam=E9ra ne marche plus.\r\n"
        ."--X\r\nContent-Type: application/pdf\r\nContent-Disposition: attachment; filename*=UTF-8''devis%20sign%C3%A9.pdf\r\nContent-Transfer-Encoding: base64\r\n\r\n".base64_encode('PDF')."\r\n--X--\r\n";

    $mail = MimeParser::parse($raw);

    expect($mail)->toMatchArray(['message_id' => 'abc@mail.sn', 'from_email' => 'amadou@teranga.sn', 'from_name' => 'Amadou Diène', 'subject' => 'Caméra en panne', 'text' => 'La caméra ne marche plus.'])
        ->and($mail['attachments'][0])->toMatchArray(['name' => 'devis signé.pdf', 'mime' => 'application/pdf', 'content' => 'PDF']);
    expect(MimeParser::parse("From: x@y.sn\nSubject: HTML\nContent-Type: text/html\n\n<p>Bonjour</p><p>&eacute;quipe</p>")['text'])->toBe("Bonjour\néquipe");
});

it('relève la boîte une seule fois par message et reconnaît le client par son adresse ou celle d\'un contact', function () {
    Storage::fake('local');
    $client = Client::factory()->create(['name' => 'Hôtel Teranga']);
    $client->emails()->create(['address' => 'contact@teranga.sn']);
    $contact = Contact::factory()->create(['client_id' => $client->id]);
    $contact->emails()->create(['address' => 'awa@teranga.sn']);

    $box = new FakeMailbox([
        11 => rawEmail('Hôtel <Contact@Teranga.sn>', 'Panne caméra', 'm1@teranga.sn', true),
        12 => rawEmail('Awa <awa@teranga.sn>', 'Devis portail', 'm2@teranga.sn'),
        13 => rawEmail('inconnu@gmail.com', 'Bonjour', 'm3@gmail.com'),
    ]);
    expect(InboxFetcher::run($box))->toBe(['imported' => 3, 'skipped' => 0])->and($box->seen)->toBe([11, 12, 13]);
    expect(InboxFetcher::run(new FakeMailbox([14 => rawEmail('x@y.sn', 'Encore', 'm1@teranga.sn')])))->toBe(['imported' => 0, 'skipped' => 1]);

    $first = InboundEmail::where('message_id', 'm1@teranga.sn')->first();
    expect($first->client_id)->toBe($client->id)->and($first->attachments[0]['name'])->toBe('plan.pdf');
    expect(InboundEmail::where('message_id', 'm2@teranga.sn')->value('client_id'))->toBe($client->id);
    expect(InboundEmail::where('message_id', 'm3@gmail.com')->value('client_id'))->toBeNull();
});

it('transforme un e-mail en demande avec ses pièces jointes, puis le lie à une tâche, une facture et un bordereau', function () {
    Storage::fake('local');
    $client = Client::factory()->create();
    $client->emails()->create(['address' => 'contact@teranga.sn']);
    InboxFetcher::run(new FakeMailbox([1 => rawEmail('Hôtel <contact@teranga.sn>', 'Panne caméra', 'p1@x', true)]));
    $email = InboundEmail::firstOrFail();

    Sanctum::actingAs(userWithRole('manager'));
    $this->getJson('/api/v1/inbound-emails')->assertJsonPath('meta.new_count', 1)->assertJsonPath('data.0.client.id', $client->id);
    $result = $this->postJson("/api/v1/inbound-emails/{$email->id}/convert")->assertCreated()->json('data');

    expect($result['email']['status']['value'])->toBe('converted')
        ->and($result['request']['source']['value'])->toBe('email')
        ->and($result['request']['contact_email'])->toBe('contact@teranga.sn')
        ->and($result['request']['body'])->toStartWith("Panne caméra\n\nLa caméra du hall")
        ->and($result['request']['client']['id'])->toBe($client->id);
    $requestId = $result['request']['id'];
    $this->getJson("/api/v1/client-requests/{$requestId}/documents")->assertJsonPath('data.0.name', 'plan.pdf');
    $this->postJson("/api/v1/inbound-emails/{$email->id}/convert")->assertStatus(422);

    // Liens vers une tâche, une facture, un bordereau
    $task = Task::factory()->create(['title' => 'Remplacer la caméra']);
    $invoice = Invoice::factory()->create(['client_id' => $client->id, 'title' => 'Facture caméra']);
    $quote = Quote::factory()->create(['client_id' => $client->id]);
    $note = DeliveryNote::forceCreate(['quote_id' => $quote->id, 'number' => 'BL-2026-0007', 'type' => 'delivery', 'title' => 'Livraison caméras', 'delivered_at' => now()]);

    $this->getJson('/api/v1/client-request-link-candidates?type=delivery_note&search=0007')->assertJsonPath('data.0.id', $note->id);
    $this->postJson("/api/v1/client-requests/{$requestId}/links", ['type' => 'task', 'id' => $task->id])->assertOk();
    $this->postJson("/api/v1/client-requests/{$requestId}/links", ['type' => 'invoice', 'id' => $invoice->id])->assertOk();
    $links = $this->postJson("/api/v1/client-requests/{$requestId}/links", ['type' => 'delivery_note', 'id' => $note->id])->json('data.links');
    expect(collect($links)->pluck('type_label')->all())->toBe(['Bordereau', 'Facture', 'Tâche'])
        ->and($links[0])->toMatchArray(['label' => 'BL-2026-0007 — Livraison caméras', 'quote_id' => $quote->id]);

    $this->deleteJson("/api/v1/client-request-links/{$links[0]['id']}")->assertJsonCount(2, 'data.links');
});

it('ignore les publicités et réserve la boîte à qui traite les demandes', function () {
    $email = InboundEmail::create(['subject' => 'Promo', 'status' => 'new', 'message_id' => 'promo@x']);
    Sanctum::actingAs(userWithRole('technician'));
    $this->getJson('/api/v1/inbound-emails')->assertForbidden();

    Sanctum::actingAs(userWithRole('sales'));
    $this->postJson("/api/v1/inbound-emails/{$email->id}/ignore")->assertJsonPath('data.status.value', 'ignored');
    $this->getJson('/api/v1/inbound-emails')->assertJsonCount(0, 'data');
    $this->postJson("/api/v1/inbound-emails/{$email->id}/ignore", ['restore' => true])->assertJsonPath('data.status.value', 'new');
    $this->postJson('/api/v1/inbound-emails/fetch')->assertStatus(422);
});

it('lit un e-mail dans un jeu de caractères inconnu de PHP et ne bloque pas la relève sur un message illisible', function () {
    $vietnamese = "From: client@example.com\r\nSubject: Panne\r\nMessage-ID: <vn-1@example.com>\r\nContent-Type: text/plain; charset=\"windows-1258\"\r\nContent-Transfer-Encoding: base64\r\n\r\n".base64_encode("Bonjour\nLe pr\xe9sident")."\r\n";
    $unknown = "From: autre@example.com\r\nSubject: Devis\r\nMessage-ID: <x-1@example.com>\r\nContent-Type: text/plain; charset=x-inconnu\r\n\r\nMerci pour le devis\r\n";
    expect(MimeParser::parse($vietnamese)['text'])->toStartWith('Bonjour')
        ->and(mb_check_encoding(MimeParser::parse($vietnamese)['text'], 'UTF-8'))->toBeTrue();
    expect(MimeParser::parse($unknown)['text'])->toBe('Merci pour le devis');

    // Un message illisible (ici, rien à lire) est compté et laissé non lu ; les autres sont relevés
    $mailbox = new FakeMailbox([1 => $vietnamese, 2 => null, 3 => $unknown]);
    $result = InboxFetcher::run($mailbox);
    expect($result)->toBe(['imported' => 2, 'skipped' => 0, 'failed' => 1])
        ->and($mailbox->seen)->toBe([1, 3]);
});
