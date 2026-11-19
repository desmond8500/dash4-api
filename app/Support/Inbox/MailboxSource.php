<?php

namespace App\Support\Inbox;

/** Boîte aux lettres relevée par Dash4 (IMAP en production, fausse boîte dans les tests). */
interface MailboxSource
{
    /** @return iterable<int|string, string> messages non lus : identifiant => message brut */
    public function unread(int $limit): iterable;

    public function markSeen(int|string $id): void;

    public function close(): void;
}
