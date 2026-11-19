<?php

namespace App\Support\Inbox;

use RuntimeException;

/**
 * Client IMAP minimal (TLS), sans extension PHP : connexion, messages non lus, marqués lus après import.
 * alwaysdata : imap.alwaysdata.com, port 993, chiffrement ssl.
 */
final class ImapMailbox implements MailboxSource
{
    /** @var resource|null */
    private $stream = null;

    private int $tag = 0;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $encryption,
        private readonly string $username,
        private readonly string $password,
        private readonly string $folder = 'INBOX',
        private readonly int $timeout = 30,
    ) {}

    public static function fromConfig(): self
    {
        $c = config('dash4.inbox');

        return new self((string) $c['host'], (int) $c['port'], (string) $c['encryption'], (string) $c['username'], (string) $c['password'], (string) $c['folder']);
    }

    public function unread(int $limit): iterable
    {
        $this->open();
        $uids = [];
        foreach ($this->command('UID SEARCH UNSEEN') as $line) {
            if (str_starts_with($line, '* SEARCH')) {
                $uids = array_map('intval', array_filter(explode(' ', trim(substr($line, 8)))));
            }
        }
        foreach (array_slice($uids, 0, $limit) as $uid) {
            yield $uid => $this->command("UID FETCH {$uid} BODY.PEEK[]", true);
        }
    }

    public function markSeen(int|string $id): void
    {
        $this->command('UID STORE '.(int) $id.' +FLAGS (\\Seen)');
    }

    public function close(): void
    {
        if ($this->stream) {
            try {
                $this->command('LOGOUT');
            } catch (\Throwable) {
            }
            fclose($this->stream);
            $this->stream = null;
        }
    }

    private function open(): void
    {
        if ($this->stream) {
            return;
        }
        $scheme = $this->encryption === 'ssl' ? 'ssl' : 'tcp';
        $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $this->host, 'SNI_enabled' => true]]);
        $stream = @stream_socket_client("{$scheme}://{$this->host}:{$this->port}", $errno, $error, $this->timeout, STREAM_CLIENT_CONNECT, $context);
        if (! $stream) {
            throw new RuntimeException("Connexion à la boîte mail impossible ({$this->host}:{$this->port}) : {$error}");
        }
        stream_set_timeout($stream, $this->timeout);
        $this->stream = $stream;
        $this->readLine();
        if ($this->encryption === 'tls') {
            $this->command('STARTTLS');
            if (! stream_socket_enable_crypto($stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('Chiffrement TLS de la boîte mail impossible.');
            }
        }
        try {
            $this->command('LOGIN '.$this->quote($this->username).' '.$this->quote($this->password));
        } catch (RuntimeException) {
            throw new RuntimeException('Connexion à la boîte mail refusée : vérifiez INBOX_USERNAME et INBOX_PASSWORD.');
        }
        $this->command('SELECT '.$this->quote($this->folder));
    }

    /** @return ($literal is true ? string : list<string>) */
    private function command(string $command, bool $literal = false): array|string
    {
        $tag = 'D'.(++$this->tag);
        fwrite($this->stream, "{$tag} {$command}\r\n");
        $lines = [];
        $data = '';
        while (true) {
            $line = $this->readLine();
            if (preg_match('/\{(\d+)\}$/', $line, $m)) {
                $data .= $this->readBytes((int) $m[1]);
                $lines[] = $line;

                continue;
            }
            if (str_starts_with($line, $tag.' ')) {
                if (! preg_match('/^'.$tag.' OK/i', $line)) {
                    throw new RuntimeException('Boîte mail : '.trim(substr($line, strlen($tag) + 1)));
                }
                break;
            }
            $lines[] = $line;
        }

        return $literal ? $data : $lines;
    }

    private function readLine(): string
    {
        $line = fgets($this->stream);
        if ($line === false) {
            throw new RuntimeException('La boîte mail ne répond plus.');
        }

        return rtrim($line, "\r\n");
    }

    private function readBytes(int $length): string
    {
        $data = '';
        while (strlen($data) < $length) {
            $chunk = fread($this->stream, min(65536, $length - strlen($data)));
            if ($chunk === false || $chunk === '') {
                throw new RuntimeException('Message incomplet reçu de la boîte mail.');
            }
            $data .= $chunk;
        }

        return $data;
    }

    private function quote(string $value): string
    {
        return '"'.addcslashes($value, '"\\').'"';
    }
}
