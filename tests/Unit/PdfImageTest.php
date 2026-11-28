<?php

use App\Support\PdfImage;

/** JPEG avec une étiquette EXIF « Orientation » (comme une photo prise téléphone tourné). */
function jpegWithOrientation(int $orientation, int $w = 40, int $h = 20): string
{
    $im = imagecreatetruecolor($w, $h);
    imagefilledrectangle($im, 0, 0, 9, 9, 0xFF0000);
    ob_start();
    imagejpeg($im);
    $jpg = ob_get_clean();
    $tiff = "II*\x00".pack('V', 8).pack('v', 1).pack('vvVvv', 0x0112, 3, 1, $orientation, 0).pack('V', 0);
    $app1 = "Exif\x00\x00".$tiff;

    return "\xFF\xD8\xFF\xE1".pack('n', strlen($app1) + 2).$app1.substr($jpg, 2);
}

it('redresse les photos selon leur orientation EXIF pour les PDF', function () {
    expect(PdfImage::normalize(jpegWithOrientation(1), 'image/jpeg'))->toBeNull();

    $fixed = PdfImage::normalize(jpegWithOrientation(6), 'image/jpeg');
    $size = getimagesizefromstring($fixed);
    expect([$size[0], $size[1]])->toBe([20, 40]);
    // Le coin rouge (en haut à gauche) se retrouve en haut à droite après le quart de tour horaire
    $color = imagecolorat(imagecreatefromstring($fixed), 15, 4);
    expect(($color >> 16) & 255)->toBeGreaterThan(200);
})->skip(fn () => ! function_exists('exif_read_data') || ! function_exists('imagecreatefromstring'), 'GD ou EXIF absent');

it('réduit les très grandes photos et laisse les autres formats tels quels', function () {
    $size = getimagesizefromstring(PdfImage::normalize(jpegWithOrientation(1, 4000, 3000), 'image/jpeg'));
    expect([$size[0], $size[1]])->toBe([1600, 1200])
        ->and(PdfImage::normalize('pas une image', 'image/png'))->toBeNull()
        ->and(PdfImage::normalize('pas une image', 'image/jpeg'))->toBeNull();
})->skip(fn () => ! function_exists('imagecreatefromstring'), 'GD absent');
