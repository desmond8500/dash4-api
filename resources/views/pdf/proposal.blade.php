@php
    $money = fn ($v) => number_format((float) $v, 0, ',', ' ');
    $qty = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, ',', ' '), '0'), ',');
    $symbol = ['XOF' => 'F CFA', 'EUR' => '€', 'USD' => '$'][$currency] ?? $currency;
    $date = fn ($d) => $d?->format('d/m/Y');
    $clientName = $options['client_label'] ?: $quote->client?->name;
    $green = '#219c90';
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
<title>Proposition technique {{ $quote->number }}</title>
@include('pdf.partials.styles')
<style>
    /* Mise en page des « Détails techniques » de Dash3 */
    .tech-title { font-size: 16px; font-weight: bold; text-transform: uppercase; color: {{ $green }}; padding-bottom: 1.5mm; border-bottom: 2px solid {{ $green }}; margin: 4mm 0 3mm; }
    .tech-section { margin-bottom: 3mm; }
    .tech-section-title { font-weight: bold; font-size: 12px; }
    .tech-section-text { font-size: 10px; margin: 1mm 0 2mm; }
    table.tech { width: 100%; border-collapse: collapse; }
    table.tech th { background: {{ $green }}; color: #fff; padding: 1.5mm 2mm; font-size: 10px; }
    table.tech td { border: 1px solid #d1d5db; padding: 2mm; vertical-align: top; font-size: 10px; }
    table.tech tr { page-break-inside: avoid; }
    .photo { width: 38mm; text-align: center; vertical-align: middle !important; }
    .photo img { max-width: 34mm; max-height: 26mm; }
    .no-photo { color: #9ca3af; font-size: 9px; padding: 8mm 0; }
    .designation { font-weight: bold; }
    .sheet-link { float: right; font-size: 9px; color: #7c3aed; }
    .tech-ref { color: #6b7280; font-size: 9.5px; border-bottom: 1px solid #9ca3af; padding-bottom: 1.5mm; margin-bottom: 1.5mm; }
    .qty { width: 16mm; text-align: center; }
    .page-break { page-break-before: always; }
</style>
</head>
<body>
@if ($options['show_footer'])
    @include('pdf.partials.header_footer', ['bank' => null, 'showLogo' => $options['show_logo']])
@endif

<h1>Proposition technique</h1>
<div class="muted">Réf. {{ $quote->number }} · {{ $date($quote->issued_at) }}</div>
<div style="margin-top:3mm"><strong>Objet :</strong> {{ $quote->title }}</div>
@if ($options['show_client'] && $clientName)<div><strong>Client :</strong> {{ $clientName }}</div>@endif
@if ($options['show_project'] && $quote->project)<div><strong>Projet :</strong> {{ $quote->project->name }}</div>@endif
@if ($options['show_introduction'] && $quote->introduction)<div class="md" style="margin-top:4mm">@markdown($quote->introduction)</div>@endif

<div class="tech-title">Détails techniques</div>

@foreach ($sections as $index => $section)
    @if ($index > 0)<div class="page-break"></div>@endif
    <div class="tech-section">
        <div class="tech-section-title">{{ $section->title }}</div>
        @if ($section->proposal)<div class="tech-section-text md">@markdown($section->proposal)</div>@endif
    </div>

    @if ($options['show_lines'] && $section->lines->isNotEmpty())
        <table class="tech">
            <thead><tr>
                <th class="photo">Photo</th>
                <th>Description</th>
                <th class="qty">Qté</th>
                @if ($options['show_prices'])<th class="num" style="width:24mm">Montant</th>@endif
            </tr></thead>
            <tbody>
            @foreach ($section->lines as $line)
                @php
                    $article = $line->article;
                    $sheet = $article?->links->first(fn ($l) => str_contains(mb_strtolower((string) $l->label), 'fiche'));
                @endphp
                <tr>
                    <td class="photo">
                        @if ($article && ($photos[$article->id] ?? null))
                            <img src="{{ $photos[$article->id] }}" alt="">
                        @else
                            <div class="no-photo">Pas de photo</div>
                        @endif
                    </td>
                    <td>
                        <div>
                            @if ($sheet)<a class="sheet-link" href="{{ $sheet->url }}">Fiche technique</a>@endif
                            <span class="designation pre">{{ $line->designation }}</span>
                        </div>
                        <div class="tech-ref pre">{{ $line->reference ?: ' ' }}</div>
                        @if ($article?->description)<div class="md">@markdown($article->description)</div>@endif
                        @if ($line->comment)<div class="pre" style="margin-top:1mm">{{ $line->comment }}</div>@endif
                    </td>
                    <td class="qty">{{ $qty($line->quantity) }}@if ($line->unit) {{ $line->unit }}@endif</td>
                    @if ($options['show_prices'])<td class="num">{{ $money($line->line_total) }} {{ $symbol }}</td>@endif
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif
@endforeach

@if ($options['show_prices'])
    <table class="totals avoid-break" style="margin-top:6mm; width:50%; margin-left:50%">
        <tr><td>Total hors taxes</td><td class="num">{{ $money($quote->subtotal) }} {{ $symbol }}</td></tr>
        <tr class="grand"><td>{{ $quote->tax_mode->value === 'vat' ? 'Total TTC' : ($quote->tax_mode->value === 'brs' ? 'Net à payer' : 'Total') }}</td><td class="num">{{ $money($quote->total) }} {{ $symbol }}</td></tr>
    </table>
@endif
</body>
</html>
