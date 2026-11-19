@php
    $money = fn ($v) => number_format((float) $v, 0, ',', ' ');
    $qty = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, ',', ' '), '0'), ',');
    $symbol = ['XOF' => 'F CFA', 'EUR' => '€', 'USD' => '$'][$currency] ?? $currency;
    $total = $contract->periodAmount();
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
<title>Contrat {{ $contract->number }}</title>
@include('pdf.partials.styles')
<style>
    h2 { font-size: 11pt; margin: 5mm 0 1.5mm; border-bottom: 1px solid #ddd; padding-bottom: 1mm; }
    .sign td { width: 50%; height: 30mm; vertical-align: top; border: 1px solid #e5e7eb; padding: 2mm; }
</style>
</head>
<body>
@include('pdf.partials.header_footer', ['bank' => null])
<h1>Contrat de maintenance {{ $contract->number }}</h1>
<div><strong>{{ $contract->title }}</strong></div>
<p>
    Entre <strong>{{ $company->name }}</strong> (le prestataire) et <strong>{{ $contract->client->name }}</strong> (le client)@if ($contract->project), pour le projet « {{ $contract->project->name }} »@endif.
</p>

<h2>Durée</h2>
<p>
    Le contrat prend effet le {{ $contract->start_date->format('d/m/Y') }}@if ($contract->end_date) et se termine le {{ $contract->end_date->format('d/m/Y') }}@else pour une durée indéterminée@endif.
    @if ($contract->end_date && $contract->tacit_renewal) Il est reconduit tacitement pour une durée identique, sauf résiliation {{ $contract->notice_days }} jours avant l'échéance.@endif
</p>

<h2>Prestations</h2>
@if ($contract->description)<div class="pre">{{ $contract->description }}</div>@endif
@if ($contract->visits_per_year)<p>Visites de maintenance prévues : <strong>{{ $contract->visits_per_year }}</strong> par an.</p>@endif
<table class="lines">
    <thead><tr><th>Désignation</th><th class="num" style="width:12%">Qté</th><th class="num" style="width:20%">Prix / période</th><th class="num" style="width:20%">Total</th></tr></thead>
    <tbody>
    @foreach ($contract->lines as $line)
        <tr>
            <td>{{ $line->designation }}</td>
            <td class="num">{{ $qty($line->quantity) }} {{ $line->unit }}</td>
            <td class="num">{{ $money($line->unit_price) }}</td>
            <td class="num">{{ $money($line->quantity * $line->unit_price) }}</td>
        </tr>
    @endforeach
    </tbody>
    <tfoot><tr><td colspan="3" class="num"><strong>Montant par période ({{ mb_strtolower($contract->billing_period->label()) }}), hors taxes</strong></td><td class="num"><strong>{{ $money($total) }} {{ $symbol }}</strong></td></tr></tfoot>
</table>

<h2>Facturation</h2>
<p>Facturation {{ mb_strtolower($contract->billing_period->label()) }}, en début de période.</p>

@if ($contract->terms)
<h2>Conditions</h2>
<div class="pre">{{ $contract->terms }}</div>
@endif

<table class="sign" style="margin-top:8mm"><tr>
    <td><span class="muted">Pour {{ $company->name }}</span>
        <div class="signs center" style="margin-top:2mm">@if ($signature)<img src="{{ $signature }}" alt="">@endif @if ($stamp)<img src="{{ $stamp }}" alt="">@endif</div></td>
    <td><span class="muted">Pour {{ $contract->client->name }} — date, nom, signature et cachet</span></td>
</tr></table>
</body>
</html>
