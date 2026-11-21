<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Markdown des zones de texte (même syntaxe que l'éditeur de l'application) rendu en HTML sûr pour
 * les PDF et les e-mails : le HTML saisi est échappé et seuls les liens http(s) et mailto sont gardés.
 */
class Markdown
{
    public static function html(?string $text): string
    {
        if ($text === null || trim($text) === '') {
            return '';
        }

        return trim(Str::markdown($text, [
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            'renderer' => ['soft_break' => "<br>\n"],
        ]));
    }
}
