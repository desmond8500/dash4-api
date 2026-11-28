<?php

namespace App\Support;

/**
 * Photos pour les PDF : DomPDF ignore l'orientation EXIF des photos de téléphone (photo couchée).
 * On applique la rotation dans les pixels et on réduit les très grandes photos (PDF plus léger).
 * Sans GD ou EXIF sur l'hébergement, ou si l'image ne se lit pas, l'original est gardé.
 */
class PdfImage
{
    /** Côté le plus long, en pixels : largement assez pour une photo de 60 mm imprimée. */
    public const MAX_SIDE = 1600;

    /** Contenu prêt pour le PDF : redressé et réduit si besoin ; `null` si rien n'a changé. */
    public static function normalize(string $bytes, string $mime): ?string
    {
        if ($mime !== 'image/jpeg' || ! function_exists('imagecreatefromstring')) {
            return null;
        }
        $orientation = self::orientation($bytes);
        $size = @getimagesizefromstring($bytes);
        if (! $size) {
            return null;
        }
        $tooBig = max($size[0], $size[1]) > self::MAX_SIDE;
        if ($orientation <= 1 && ! $tooBig) {
            return null;
        }
        $image = @imagecreatefromstring($bytes);
        if (! $image) {
            return null;
        }
        if ($tooBig) {
            $ratio = self::MAX_SIDE / max($size[0], $size[1]);
            $scaled = imagescale($image, max(1, (int) round($size[0] * $ratio)), max(1, (int) round($size[1] * $ratio)));
            if ($scaled) {
                imagedestroy($image);
                $image = $scaled;
            }
        }
        $image = self::orient($image, $orientation);
        ob_start();
        imagejpeg($image, null, 85);
        imagedestroy($image);

        return ob_get_clean() ?: null;
    }

    /** Valeur EXIF « Orientation » (1 à 8), 1 si absente ou illisible. */
    public static function orientation(string $bytes): int
    {
        if (! function_exists('exif_read_data')) {
            return 1;
        }
        $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($bytes));
        $value = (int) ($exif['Orientation'] ?? 1);

        return $value >= 1 && $value <= 8 ? $value : 1;
    }

    /** Applique l'orientation EXIF (rotations et miroirs). */
    private static function orient(\GdImage $image, int $orientation): \GdImage
    {
        if (in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($image, $orientation === 4 ? IMG_FLIP_VERTICAL : IMG_FLIP_HORIZONTAL);
        }
        // imagerotate tourne dans le sens inverse des aiguilles d'une montre
        $angle = match ($orientation) {
            3, 4 => 180,
            5, 6 => 270,
            7, 8 => 90,
            default => 0,
        };
        if ($angle) {
            $rotated = imagerotate($image, $angle, 0);
            if ($rotated) {
                imagedestroy($image);

                return $rotated;
            }
        }

        return $image;
    }
}
