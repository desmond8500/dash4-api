@php
    $money = fn ($v) => number_format((float) $v, 0, ',', ' ');
    $qty = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, ',', ' '), '0'), ',');
    $symbol = ['XOF' => 'F CFA', 'EUR' => '€', 'USD' => '$'][$currency] ?? $currency;
    $date = fn ($d) => $d?->format('d/m/Y');
    $rate = fn ($v) => rtrim(rtrim(number_format($v, 2, ',', ''), '0'), ',');
    $draft = $invoice->status->value === 'draft';
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
<title>{{ $invoice->number ?? 'Brouillon' }}</title>
@include('pdf.partials.styles')
<style>
    .watermark { position: fixed; top: 40%; left: 0; right: 0; text-align: center; font-size: 72px; color: rgba(220, 38, 38, 0.12); transform: rotate(-30deg); }
    .paid { color: #15803d; font-weight: bold; }
</style>
</head>
<body>
@include('pdf.partials.header_footer', ['bank' => $invoice->bankAccount])
@if ($draft)<div class="watermark">BROUILLON</div>@endif
@if ($invoice->status->value === 'cancelled')<div class="watermark">ANNULÉE</div>@endif

<table class="meta"><tr>
    <td style="width:55%">
        <h1>Facture {{ $invoice->number ?? '(brouillon)' }}</h1>
        <div><strong>{{ $invoice->title }}</strong></div>
        @if ($invoice->project)<div class="muted">Projet : {{ $invoice->project->name }}</div>@endif
        @if ($invoice->quote)<div class="muted">Devis : {{ $invoice->quote->number }}</div>@endif
        <div class="muted">
            Date : {{ $date($invoice->issued_at) ?? '—' }}
            @if ($invoice->due_at) · À régler avant le {{ $date($invoice->due_at) }}@endif
        </div>
    </td>
    <td>
        <div class="box">
            <strong>{{ $invoice->client?->name }}</strong><br>
            @if ($invoice->client?->address)<span class="pre">{{ $invoice->client->address }}</span><br>@endif
            @if ($invoice->client?->phones->first()){{ $invoice->client->phones->first()->number }}<br>@endif
            @if ($invoice->client?->emails->first()){{ $invoice->client->emails->first()->address }}@endif
        </div>
    </td>
</tr></table>

@if ($invoice->introduction)<div class="md" style="margin-top:5mm">@markdown($invoice->introduction)</div>@endif

<table class="lines">
    <thead><tr>
        <th>Désignation</th>
        <th class="center" style="width:13%">Quantité</th>
        <th class="num" style="width:17%">Prix unitaire</th>
        <th class="num" style="width:18%">Montant</th>
    </tr></thead>
    <tbody>
    @foreach ($invoice->lines as $line)
        <tr>
            <td><div class="pre">{{ $line->designation }}</div>@if ($line->reference)<div class="ref pre">{{ $line->reference }}</div>@endif</td>
            <td class="center">{{ $qty($line->quantity) }}@if ($line->unit) {{ $line->unit }}@endif</td>
            <td class="num">{{ $money($line->unit_price) }}</td>
            <td class="num">{{ $money($line->line_total) }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

<table class="avoid-break" style="margin-top:6mm"><tr>
    <td class="signs center" style="width:52%; vertical-align:middle">
        @if ($signature)<img src="{{ $signature }}" alt="">@endif
        @if ($stamp)<img src="{{ $stamp }}" alt="">@endif
    </td>
    <td>
        <table class="totals">
            <tr><td>Total hors taxes</td><td class="num">{{ $money($invoice->subtotal) }} {{ $symbol }}</td></tr>
            @if ($invoice->tax_mode->value === 'vat')
                <tr><td>TVA ({{ $rate($invoice->vat_rate) }} %)</td><td class="num">{{ $money($invoice->tax_amount) }} {{ $symbol }}</td></tr>
                <tr class="grand"><td>Total TTC</td><td class="num">{{ $money($invoice->total) }} {{ $symbol }}</td></tr>
            @elseif ($invoice->tax_mode->value === 'brs')
                <tr><td>Retenue BRS ({{ $rate($invoice->brs_rate) }} %)</td><td class="num">− {{ $money($invoice->tax_amount) }} {{ $symbol }}</td></tr>
                <tr class="grand"><td>Net à payer</td><td class="num">{{ $money($invoice->total) }} {{ $symbol }}</td></tr>
            @else
                <tr class="grand"><td>Total</td><td class="num">{{ $money($invoice->total) }} {{ $symbol }}</td></tr>
            @endif
            @if ($invoice->credited_amount > 0)
                <tr><td>Avoir(s) {{ $invoice->creditNotes->pluck('number')->implode(', ') }}</td><td class="num">− {{ $money($invoice->credited_amount) }} {{ $symbol }}</td></tr>
            @endif
            @if ($invoice->paid_amount > 0 || $invoice->credited_amount > 0)
                @if ($invoice->paid_amount > 0)<tr><td>Déjà réglé</td><td class="num">− {{ $money($invoice->paid_amount) }} {{ $symbol }}</td></tr>@endif
                <tr><td><strong>Reste à payer</strong></td><td class="num"><strong>{{ $money($invoice->remainingAmount()) }} {{ $symbol }}</strong></td></tr>
            @endif
        </table>
    </td>
</tr></table>

@if ($invoice->status->value === 'paid')<p class="paid" style="margin-top:4mm">Facture acquittée le {{ $date($invoice->paid_at) }}.</p>@endif

@if ($invoice->payments->isNotEmpty())
<div class="avoid-break" style="margin-top:5mm">
    <strong>Paiements reçus</strong>
    <table class="lines" style="margin-top:2mm">
        <thead><tr><th style="width:18%">Date</th><th>Mode</th><th>Référence</th><th class="num" style="width:20%">Montant</th></tr></thead>
        @foreach ($invoice->payments as $payment)
            <tr><td>{{ $date($payment->paid_at) }}</td><td>{{ $payment->method->label() }}</td><td>{{ $payment->reference }}</td><td class="num">{{ $money($payment->amount) }} {{ $symbol }}</td></tr>
        @endforeach
    </table>
</div>
@endif

@if ($invoice->payment_terms)<div class="avoid-break" style="margin-top:5mm"><strong>Modalités de paiement</strong><div class="md">@markdown($invoice->payment_terms)</div></div>@endif
</body>
</html>
