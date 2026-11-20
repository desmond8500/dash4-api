<?php

namespace App\Support\Inbox;

/**
 * Lit un e-mail brut (RFC 5322 / MIME) : en-têtes décodés, texte, HTML et pièces jointes.
 * Sans extension PHP particulière (mbstring et iconv suffisent).
 */
final class MimeParser
{
    /**
     * @return array{message_id: ?string, from_email: ?string, from_name: ?string, to: ?string, subject: string,
     *   date: ?\DateTimeImmutable, text: string, html: ?string, attachments: list<array{name: string, mime: string, content: string}>}
     */
    public static function parse(string $raw): array
    {
        [$headers, $body] = self::split($raw);
        $from = self::address(self::decodeHeader($headers['from'] ?? ''));
        $result = ['text' => '', 'html' => null, 'attachments' => []];
        self::walk($headers, $body, $result);

        $text = trim($result['text']) !== '' ? $result['text'] : ($result['html'] ? self::htmlToText($result['html']) : '');
        $date = null;
        if (! empty($headers['date'])) {
            try {
                $date = new \DateTimeImmutable(preg_replace('/\s*\([^)]*\)\s*$/', '', $headers['date']));
            } catch (\Throwable) {
                $date = null;
            }
        }

        return [
            'message_id' => isset($headers['message-id']) ? trim($headers['message-id'], " <>\t") : null,
            'from_email' => $from['email'],
            'from_name' => $from['name'],
            'to' => isset($headers['to']) ? self::decodeHeader($headers['to']) : null,
            'subject' => self::decodeHeader($headers['subject'] ?? ''),
            'date' => $date,
            'text' => self::cleanText($text),
            'html' => $result['html'],
            'attachments' => $result['attachments'],
        ];
    }

    /** @return array{0: array<string, string>, 1: string} */
    private static function split(string $raw): array
    {
        $raw = str_replace("\r\n", "\n", $raw);
        $pos = strpos($raw, "\n\n");
        $head = $pos === false ? $raw : substr($raw, 0, $pos);
        $body = $pos === false ? '' : substr($raw, $pos + 2);
        $head = preg_replace("/\n[ \t]+/", ' ', $head);
        $headers = [];
        foreach (explode("\n", $head) as $line) {
            if (! str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $name = strtolower(trim($name));
            $headers[$name] ??= trim($value);
        }

        return [$headers, $body];
    }

    private static function walk(array $headers, string $body, array &$result): void
    {
        [$type, $params] = self::contentType($headers['content-type'] ?? 'text/plain; charset=us-ascii');
        $disposition = strtolower(trim(explode(';', $headers['content-disposition'] ?? '')[0]));
        $filename = self::filename($headers);

        if (str_starts_with($type, 'multipart/') && ! empty($params['boundary'])) {
            $parts = explode('--'.$params['boundary'], $body);
            array_shift($parts);
            foreach ($parts as $part) {
                if (str_starts_with($part, '--')) {
                    break;
                }
                [$h, $b] = self::split(ltrim($part, "\r\n"));
                self::walk($h, $b, $result);
            }

            return;
        }

        $content = self::decodeBody($body, strtolower($headers['content-transfer-encoding'] ?? '7bit'));
        $isAttachment = $disposition === 'attachment' || ! str_starts_with($type, 'text/') || ($filename !== null && $disposition !== 'inline');

        if (! $isAttachment && $type === 'text/plain') {
            $result['text'] .= ($result['text'] !== '' ? "\n" : '').self::toUtf8($content, $params['charset'] ?? null);
        } elseif (! $isAttachment && $type === 'text/html') {
            $result['html'] = ($result['html'] ?? '').self::toUtf8($content, $params['charset'] ?? null);
        } elseif ($content !== '') {
            $result['attachments'][] = [
                'name' => $filename ?? ($type === 'message/rfc822' ? 'message.eml' : 'piece-jointe'),
                'mime' => $type,
                'content' => $content,
            ];
        }
    }

    /** @return array{0: string, 1: array<string, string>} */
    private static function contentType(string $value): array
    {
        $pieces = explode(';', $value);
        $type = strtolower(trim(array_shift($pieces)));
        $params = [];
        foreach ($pieces as $p) {
            if (str_contains($p, '=')) {
                [$k, $v] = explode('=', $p, 2);
                $params[strtolower(trim($k))] = trim(trim($v), '"');
            }
        }

        return [$type ?: 'text/plain', $params];
    }

    private static function filename(array $headers): ?string
    {
        foreach ([$headers['content-disposition'] ?? '', $headers['content-type'] ?? ''] as $h) {
            // RFC 2231 : filename*=UTF-8''nom%20encod%C3%A9.pdf
            if (preg_match("/(?:file)?name\*=([^']*)'[^']*'([^;]+)/i", $h, $m)) {
                return self::safeName(self::toUtf8(rawurldecode(trim($m[2], '" ')), $m[1] ?: null));
            }
            if (preg_match('/(?:file)?name="?([^";]+)"?/i', $h, $m)) {
                return self::safeName(self::decodeHeader(trim($m[1])));
            }
        }

        return null;
    }

    private static function safeName(string $name): string
    {
        $name = trim(str_replace(['/', '\\', "\0"], '-', $name));

        return mb_substr($name !== '' ? $name : 'piece-jointe', 0, 180);
    }

    private static function decodeBody(string $body, string $encoding): string
    {
        return match ($encoding) {
            'base64' => (string) base64_decode(preg_replace('/\s+/', '', $body), false),
            'quoted-printable' => quoted_printable_decode(str_replace("=\n", '', $body)),
            default => $body,
        };
    }

    public static function decodeHeader(string $value): string
    {
        if (! str_contains($value, '=?')) {
            return trim($value);
        }
        $decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');

        return trim($decoded !== false ? $decoded : mb_decode_mimeheader($value));
    }

    /** @return array{email: ?string, name: ?string} */
    public static function address(string $value): array
    {
        if (preg_match('/^\s*"?([^"<]*?)"?\s*<([^>]+)>/', $value, $m)) {
            return ['email' => strtolower(trim($m[2])), 'name' => trim($m[1]) !== '' ? trim($m[1]) : null];
        }
        $email = trim($value, " <>\t");

        return ['email' => filter_var($email, FILTER_VALIDATE_EMAIL) ? strtolower($email) : null, 'name' => null];
    }

    /**
     * Texte converti en UTF-8. Un jeu de caractères inconnu de PHP (windows-1258, x-mac-…) ne fait
     * jamais échouer la lecture : iconv est essayé, puis Windows-1252 en dernier recours.
     */
    private static function toUtf8(string $content, ?string $charset): string
    {
        $charset = strtolower(trim((string) $charset, " \t\"'"));
        if ($charset === '' || $charset === 'utf-8' || $charset === 'utf8' || $charset === 'us-ascii') {
            return self::fallback($content);
        }
        if (in_array($charset, ['iso-8859-1', 'latin1', 'latin-1'], true)) {
            $charset = 'windows-1252';
        }
        if (self::mbSupports($charset)) {
            try {
                return mb_convert_encoding($content, 'UTF-8', $charset);
            } catch (\ValueError) {
                // essayé autrement ci-dessous
            }
        }
        if (function_exists('iconv')) {
            $converted = @iconv($charset, 'UTF-8//TRANSLIT//IGNORE', $content);
            if ($converted !== false && $converted !== '') {
                return $converted;
            }
        }

        return self::fallback($content);
    }

    private static function fallback(string $content): string
    {
        return mb_check_encoding($content, 'UTF-8') ? $content : mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
    }

    private static function mbSupports(string $charset): bool
    {
        static $known = null;
        if ($known === null) {
            $known = [];
            foreach (mb_list_encodings() as $encoding) {
                $known[strtolower($encoding)] = true;
                foreach (mb_encoding_aliases($encoding) as $alias) {
                    $known[strtolower($alias)] = true;
                }
            }
        }

        return isset($known[$charset]);
    }

    public static function htmlToText(string $html): string
    {
        $html = preg_replace('#<(script|style|head)[^>]*>.*?</\1>#is', '', $html);
        $html = preg_replace('#<br\s*/?>|</p>|</div>|</li>|</tr>|</h[1-6]>#i', "\n", $html);

        return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private static function cleanText(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace("/[ \t]+\n/", "\n", $text);
        $text = preg_replace("/\n{3,}/", "\n\n", $text);

        return trim($text);
    }
}
