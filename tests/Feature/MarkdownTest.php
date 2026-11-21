<?php

use App\Support\Markdown;

it('rend le Markdown des zones de texte en HTML sûr', function () {
    $html = Markdown::html("## Travaux\n**Pose** de 2 caméras\n- câble\n- coffret");
    expect($html)->toContain('<h2>Travaux</h2>')
        ->toContain('<strong>Pose</strong> de 2 caméras')
        ->toContain('<li>câble</li>');
    expect(Markdown::html("ligne 1\nligne 2"))->toContain('ligne 1<br>');
});

it('échappe le HTML saisi et retire les liens dangereux', function () {
    expect(Markdown::html('<script>alert(1)</script>'))->not->toContain('<script>');
    expect(Markdown::html('[clic](javascript:alert(1))'))->not->toContain('javascript:');
    expect(Markdown::html('  '))->toBe('');
    expect(Markdown::html(null))->toBe('');
});

it('fournit la directive @markdown aux vues', function () {
    $html = \Illuminate\Support\Facades\Blade::render('<div>@markdown($t)</div>', ['t' => '**fait**']);
    expect($html)->toContain('<strong>fait</strong>');
});
