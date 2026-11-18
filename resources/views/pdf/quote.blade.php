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
<style>
    @page { margin: 28mm 14mm 22mm 14mm; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1f2937; }
    header { position: fixed; top: -22mm; left: 0; right: 0; height: 20mm; }
    footer { position: fixed; bottom: -16mm; left: 0; right: 0; height: 14mm; font-size: 8px; color: #6b7280; text-align: center; border-top: 1px solid #e5e7eb; padding-top: 2mm; }
    footer .page:after { content: counter(page) " / " counter(pages); }
    .logo { max-height: 18mm; max-width: 60mm; }
    .company { text-align: right; font-size: 9px; color: #4b5563; }
    .company strong { font-size: 11px; color: #111827; }
    h1 { font-size: 18px; margin: 0 0 2mm; color: #111827; }
    .muted { color: #6b7280; }
    .pre { white-space: pre-line; }
    table { width: 100%; border-collapse: collapse; }
    .meta td { vertical-align: top; padding: 0; }
    .box { border: 1px solid #e5e7eb; border-radius: 4px; padding: 3mm; }
    .lines { margin-top: 5mm; }
    .lines th { background: #f3f4f6; text-align: left; padding: 2mm; font-size: 9px; text-transform: uppercase; color: #374151; }
    .lines td { padding: 1.6mm 2mm; border-bottom: 1px solid #f0f0f0; vertical-align: top; }
    .lines .section td { background: #eef2ff; font-weight: bold; color: #1e3a8a; border-bottom: none; }
    .lines .subtotal td { font-weight: bold; text-align: right; border-bottom: 1px solid #d1d5db; }
    .num { text-align: right; white-space: nowrap; }
    .center { text-align: center; }
    .ref { font-size: 8.5px; color: #6b7280; }
    .zero td { color: #9ca3af; }
    .totals td { padding: 1.4mm 2mm; }
    .totals .grand td { font-size: 12px; font-weight: bold; background: #111827; color: #fff; }
    .signs img { max-height: 30mm; max-width: 45mm; margin: 0 2mm; }
    .avoid-break { page-break-inside: avoid; }
</style>
</head>
<body>
<header>
    <table><tr>
        <td style="width:50%">@if ($logo)<img class="logo" src="{{ $logo }}" alt="">@else<strong style="font-size:14px">{{ $company->name }}</strong>@endif</td>
        <td class="company">
            <strong>{{ $company->name }}</strong>@if ($company->legal_form) — {{ $company->legal_form }}@endif<br>
            @if ($company->address){{ $company->address }}@if ($company->city), {{ $company->city }}@endif<br>@endif
            @if ($company->phone){{ $company->phone }}@endif @if ($company->email) · {{ $company->email }}@endif
        </td>
    </tr></table>
</header>

<footer>
    {{ $company->name }}
    @if ($company->ninea) · NINEA {{ $company->ninea }}@endif
    @if ($company->rccm) · RCCM {{ $company->rccm }}@endif
    @if ($company->share_capital) · Capital {{ $company->share_capital }}@endif
    @if ($quote->bankAccount)<br>{{ $quote->bankAccount->bank_name }} · {{ $quote->bankAccount->account_number }}@if ($quote->bankAccount->iban) · IBAN {{ $quote->bankAccount->iban }}@endif @if ($quote->bankAccount->bic) · BIC {{ $quote->bankAccount->bic }}@endif @endif
    @if ($company->legal_mentions)<br>{{ $company->legal_mentions }}@endif
    <div class="page">Page </div>
</footer>

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

@if ($quote->introduction)<p class="pre" style="margin-top:5mm">{{ $quote->introduction }}</p>@endif

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

@if ($quote->payment_terms)<div class="avoid-break" style="margin-top:5mm"><strong>Modalités de paiement</strong><div class="pre">{{ $quote->payment_terms }}</div></div>@endif

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
