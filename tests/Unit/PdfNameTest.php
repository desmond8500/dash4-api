<?php

use App\Support\PdfName;

it('nomme un PDF « Client - Objet - Référence », sans morceaux vides ni caractères interdits', function () {
    expect(PdfName::make(['Hôtel Teranga', 'Vidéosurveillance', 'DEV-2026-0001']))->toBe('Hôtel Teranga - Vidéosurveillance - DEV-2026-0001.pdf')
        ->and(PdfName::make(['Proposition technique', 'SARL A/B', 'Alarme : RDC', null]))->toBe('Proposition technique - SARL A B - Alarme RDC.pdf')
        ->and(PdfName::make([null, '', '  ']))->toBe('Document.pdf')
        ->and(PdfName::make(['Client', 'Objet'], 'csv'))->toBe('Client - Objet.csv');
});

it('raccourcit un objet trop long', function () {
    $name = PdfName::make(['Client', str_repeat('Très long objet ', 20), 'DEV-1']);

    expect(mb_strlen($name))->toBeLessThan(110)->and($name)->toContain('…')->toEndWith(' - DEV-1.pdf');
});
