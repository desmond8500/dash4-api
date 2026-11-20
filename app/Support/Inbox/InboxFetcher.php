<?php

namespace App\Support\Inbox;

use App\Models\Contact;
use App\Models\Email;
use App\Models\InboundEmail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Relève la boîte des demandes : chaque message non lu devient un e-mail de la boîte de réception
 * (une seule fois grâce au Message-ID), le client est reconnu par son adresse, les pièces jointes
 * sont gardées (20 Mo au plus chacune), puis le message est marqué lu sur le serveur.
 * Un message illisible n'arrête pas la relève : il est noté dans le journal, compté dans `failed`
 * et laissé non lu dans la boîte (à ouvrir dans la messagerie) ; les suivants sont relevés.
 */
final class InboxFetcher
{
    public const MAX_ATTACHMENT = 20 * 1024 * 1024;

    /** @return array{imported: int, skipped: int, failed: int} */
    public static function run(MailboxSource $mailbox, int $limit = 50): array
    {
        $imported = 0;
        $skipped = 0;
        $failed = 0;
        try {
            foreach ($mailbox->unread($limit) as $id => $raw) {
                try {
                    $mail = MimeParser::parse($raw);
                    $messageId = $mail['message_id'] ?: 'sans-id-'.sha1($raw);
                    if (InboundEmail::withTrashed()->where('message_id', $messageId)->exists()) {
                        $skipped++;
                    } else {
                        self::store($mail, $messageId);
                        $imported++;
                    }
                    $mailbox->markSeen($id);
                } catch (\Throwable $e) {
                    $failed++;
                    Log::warning('Boîte des demandes : e-mail illisible, laissé dans la boîte', [
                        'mailbox_id' => $id,
                        'subject' => preg_match('/^Subject:\s*(.+)$/mi', substr((string) $raw, 0, 20000), $m) ? mb_substr(trim($m[1]), 0, 200) : null,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        } finally {
            $mailbox->close();
        }

        return ['imported' => $imported, 'skipped' => $skipped, 'failed' => $failed];
    }

    private static function store(array $mail, string $messageId): InboundEmail
    {
        $email = InboundEmail::create([
            'message_id' => mb_substr($messageId, 0, 255),
            'from_email' => $mail['from_email'],
            'from_name' => $mail['from_name'] ? mb_substr($mail['from_name'], 0, 255) : null,
            'to' => $mail['to'] ? mb_substr($mail['to'], 0, 255) : null,
            'subject' => mb_substr($mail['subject'] !== '' ? $mail['subject'] : '(sans objet)', 0, 255),
            'body_text' => $mail['text'],
            'body_html' => $mail['html'],
            'received_at' => $mail['date'] ?? now(),
            'client_id' => self::clientFor($mail['from_email']),
            'status' => 'new',
        ]);

        $files = [];
        foreach ($mail['attachments'] as $i => $a) {
            if (strlen($a['content']) > self::MAX_ATTACHMENT) {
                continue;
            }
            $path = "inbox/{$email->id}/".$i.'-'.Str::slug(pathinfo($a['name'], PATHINFO_FILENAME)).'.'.(pathinfo($a['name'], PATHINFO_EXTENSION) ?: 'bin');
            Storage::disk('local')->put($path, $a['content']);
            $files[] = ['name' => $a['name'], 'mime' => $a['mime'], 'size' => strlen($a['content']), 'path' => $path];
        }
        $email->forceFill(['attachments' => $files])->save();

        return $email;
    }

    /** Client reconnu par une adresse de sa fiche ou de l'un de ses contacts. */
    public static function clientFor(?string $address): ?int
    {
        if (! $address) {
            return null;
        }
        $match = Email::query()->whereRaw('LOWER(address) = ?', [strtolower($address)])->first();
        if (! $match) {
            return null;
        }

        return match ($match->emailable_type) {
            'client' => (int) $match->emailable_id,
            'contact' => Contact::withTrashed()->find($match->emailable_id)?->client_id,
            default => null,
        };
    }
}
