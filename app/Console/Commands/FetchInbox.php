<?php

namespace App\Console\Commands;

use App\Support\Inbox\ImapMailbox;
use App\Support\Inbox\InboxFetcher;
use Illuminate\Console\Command;

/** Relève la boîte mail des demandes (INBOX_HOST… dans le .env) ; planifiée toutes les 5 minutes. */
class FetchInbox extends Command
{
    protected $signature = 'inbox:fetch {--limit=50}';

    protected $description = 'Relève les e-mails reçus sur l\'adresse des demandes';

    public function handle(): int
    {
        if (! config('dash4.inbox.host') || ! config('dash4.inbox.username')) {
            $this->warn('Boîte mail non configurée (INBOX_HOST, INBOX_USERNAME, INBOX_PASSWORD).');

            return self::SUCCESS;
        }
        $result = InboxFetcher::run(ImapMailbox::fromConfig(), (int) $this->option('limit'));
        $this->info("{$result['imported']} e-mail(s) importé(s), {$result['skipped']} déjà connu(s).");
        if ($result['failed']) {
            $this->warn("{$result['failed']} e-mail(s) illisible(s), laissé(s) dans la boîte (détail dans storage/logs).");
        }

        return self::SUCCESS;
    }
}
