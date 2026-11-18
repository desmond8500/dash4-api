@php
    $money = fn ($v) => number_format((float) $v, 0, ',', ' ');
    $qty = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, ',', ' '), '0'), ',');
    $symbol = ['XOF' => 'F CFA', 'EUR' => '€', 'USD' => '$'][$currency] ?? $currency;
    $date = fn ($d) => $d?->format('d/m/Y');
    $clientName = $options['client_label'] ?: $quote->client?->name;
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
<title>Proposition technique {{ $quote->number }}</title>
@include('pdf.partials.styles')
<style>
    .proposal-text { margin: 2mm 0 3mm; white-space: pre-line; }
    h2 { font-size: 13px; color: #1e3a8a; margin: 6mm 0 1mm; border-bottom: 1px solid #c7d2fe; padding-bottom: 1mm; }
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
@if ($options['show_introduction'] && $quote->introduction)<p class="pre" style="margin-top:4mm">{{ $quote->introduction }}</p>@endif

@foreach ($quote->sections as $index => $section)
    <h2>{{ $index + 1 }}. {{ $section->title }}</h2>
    @if ($section->proposal)<div class="proposal-text">{{ $section->proposal }}</div>@endif
    @if ($options['show_lines'] && $section->lines->isNotEmpty())
        <table class="lines">
            <thead><tr>
                <th>Équipement / prestation</th>
                <th class="center" style="width:14%">Quantité</th>
                @if ($options['show_prices'])<th class="num" style="width:18%">Montant</th>@endif
            </tr></thead>
            <tbody>
            @foreach ($section->lines as $line)
                <tr>
                    <td><div class="pre">{{ $line->designation }}</div>@if ($line->reference)<div class="ref pre">{{ $line->reference }}</div>@endif</td>
                    <td class="center">{{ $qty($line->quantity) }}@if ($line->unit) {{ $line->unit }}@endif</td>
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
