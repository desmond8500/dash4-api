@php
    /** Montant en F CFA : « 1 250 000 » (sans décimales). */
    $money = fn ($v) => number_format((float) $v, 0, ',', ' ');
    $qty = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, ',', ' '), '0'), ',');
    $symbol = ['XOF' => 'F CFA', 'EUR' => '€', 'USD' => '$'][$currency] ?? $currency;
    $date = fn ($d) => $d?->format('d/m/Y');
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
<title>{{ $quote->number }}</title>
@include('pdf.partials.styles')
</head>
<body>
@include('pdf.partials.header_footer', ['bank' => $quote->bankAccount])

<table class="meta"><tr>
    <td style="width:55%">
        <h1>Devis {{ $quote->number }}</h1>
        <div><strong>Objet :</strong> {{ $quote->title }}</div>
        @if ($quote->project)<div class="muted">Projet : {{ $quote->project->name }}</div>@endif
        <div class="muted">Date : {{ $date($quote->issued_at) }}@if ($quote->valid_until) · Valable jusqu'au {{ $date($quote->valid_until) }}@endif</div>
    </td>
    <td>
        <div class="box">
            <strong>{{ $quote->client?->name }}</strong><br>
            @if ($quote->client?->address)<span class="pre">{{ $quote->client->address }}</span><br>@endif
            @if ($quote->client?->phones->first()){{ $quote->client->phones->first()->number }}<br>@endif
            @if ($quote->client?->emails->first()){{ $quote->client->emails->first()->address }}@endif
        </div>
    </td>
</tr></table>

@if ($quote->introduction)<div class="md" style="margin-top:5mm">@markdown($quote->introduction)</div>@endif

<table class="lines">
    <thead><tr>
        <th>Désignation</th>
        <th class="center" style="width:13%">Quantité</th>
        <th class="num" style="width:17%">Prix unitaire</th>
        <th class="num" style="width:18%">Montant</th>
    </tr></thead>
    @foreach ($quote->sections as $section)
        <tbody>
        <tr class="section"><td colspan="4">{{ $section->title }}</td></tr>
        @foreach ($section->lines as $line)
            <tr @class(['zero' => $line->quantity == 0 || $line->unit_price == 0])>
                <td>
                    <div class="pre">{{ $line->designation }}</div>
                    @if ($line->reference)<div class="ref pre">{{ $line->reference }}</div>@endif
                </td>
                <td class="center">{{ $qty($line->quantity) }}@if ($line->unit) {{ $line->unit }}@endif</td>
                <td class="num">{{ $money($line->unit_price) }}</td>
                <td class="num">{{ $money($line->line_total) }}</td>
            </tr>
        @endforeach
        <tr class="subtotal"><td colspan="3">Sous-total {{ $section->title }}</td><td class="num">{{ $money($section->lines->sum('line_total')) }}</td></tr>
        </tbody>
    @endforeach
</table>

<table class="avoid-break" style="margin-top:6mm"><tr>
    <td class="signs center" style="width:52%; vertical-align:middle">
        @if ($signature)<img src="{{ $signature }}" alt="">@endif
        @if ($stamp)<img src="{{ $stamp }}" alt="">@endif
    </td>
    <td>
        <table class="totals">
            <tr><td>Total hors taxes</td><td class="num">{{ $money($quote->subtotal) }} {{ $symbol }}</td></tr>
            @if ($quote->discount_amount > 0)
                <tr><td>Remise ({{ rtrim(rtrim(number_format($quote->discount_rate, 2, ',', ''), '0'), ',') }} %)</td><td class="num">− {{ $money($quote->discount_amount) }} {{ $symbol }}</td></tr>
                <tr><td>Net hors taxes</td><td class="num">{{ $money($quote->subtotal - $quote->discount_amount) }} {{ $symbol }}</td></tr>
            @endif
            @if ($quote->tax_mode->value === 'vat')
                <tr><td>TVA ({{ rtrim(rtrim(number_format($quote->vat_rate, 2, ',', ''), '0'), ',') }} %)</td><td class="num">{{ $money($quote->tax_amount) }} {{ $symbol }}</td></tr>
                <tr class="grand"><td>Total TTC</td><td class="num">{{ $money($quote->total) }} {{ $symbol }}</td></tr>
            @elseif ($quote->tax_mode->value === 'brs')
                <tr><td>Retenue BRS ({{ rtrim(rtrim(number_format($quote->brs_rate, 2, ',', ''), '0'), ',') }} %)</td><td class="num">− {{ $money($quote->tax_amount) }} {{ $symbol }}</td></tr>
                <tr class="grand"><td>Net à payer</td><td class="num">{{ $money($quote->total) }} {{ $symbol }}</td></tr>
            @else
                <tr class="grand"><td>Total</td><td class="num">{{ $money($quote->total) }} {{ $symbol }}</td></tr>
            @endif
        </table>
    </td>
</tr></table>

@if ($quote->deposits->isNotEmpty())
<div class="avoid-break" style="margin-top:5mm">
    <strong>Acomptes</strong>
    <table class="lines" style="margin-top:2mm">
        <thead><tr><th>Libellé</th><th style="width:20%">Échéance</th><th style="width:15%">État</th><th class="num" style="width:20%">Montant</th></tr></thead>
        @foreach ($quote->deposits as $deposit)
            <tr>
                <td>{{ $deposit->label }}@if ($deposit->description)<div class="ref">{{ $deposit->description }}</div>@endif</td>
                <td>{{ $date($deposit->due_date) ?: '—' }}</td>
                <td>{{ $deposit->is_paid ? 'Reçu le '.$date($deposit->paid_at) : 'À régler' }}</td>
                <td class="num">{{ $money($deposit->amount) }} {{ $symbol }}</td>
            </tr>
        @endforeach
        @php $paid = $quote->deposits->where('is_paid', true)->sum('amount'); @endphp
        @if ($paid > 0)
            <tr class="subtotal"><td colspan="3">Reste à payer</td><td class="num">{{ $money($quote->total - $paid) }} {{ $symbol }}</td></tr>
        @endif
    </table>
</div>
@endif

@if ($quote->payment_terms)<div class="avoid-break" style="margin-top:5mm"><strong>Modalités de paiement</strong><div class="md">@markdown($quote->payment_terms)</div></div>@endif

<div class="avoid-break" style="margin-top:8mm">
    <table><tr>
        <td style="width:50%"></td>
        <td class="box" style="height:25mm; vertical-align:top">
            <strong>Bon pour accord</strong> — date, nom, signature et cachet du client
        </td>
    </tr></table>
</div>

@if ($company->terms)<div style="page-break-before: always"><strong>Conditions générales</strong><div class="pre" style="font-size:8.5px">{{ $company->terms }}</div></div>@endif
</body>
</html>
