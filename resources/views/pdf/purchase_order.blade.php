@php
    $money = fn ($v) => number_format((float) $v, 0, ',', ' ');
    $qty = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, ',', ' '), '0'), ',');
    $symbol = ['XOF' => 'F CFA', 'EUR' => '€', 'USD' => '$'][$currency] ?? $currency;
    $date = fn ($d) => $d?->format('d/m/Y');
    $rate = fn ($v) => rtrim(rtrim(number_format($v, 2, ',', ''), '0'), ',');
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
<title>{{ $purchase->number }}</title>
@include('pdf.partials.styles')
<style>
    .watermark { position: fixed; top: 40%; left: 0; right: 0; text-align: center; font-size: 72px; color: rgba(220, 38, 38, 0.12); transform: rotate(-30deg); }
</style>
</head>
<body>
@include('pdf.partials.header_footer', ['bank' => null])
@if ($purchase->status->value === 'draft')<div class="watermark">BROUILLON</div>@endif
@if ($purchase->status->value === 'cancelled')<div class="watermark">ANNULÉ</div>@endif

<table class="meta"><tr>
    <td style="width:55%">
        <h1>Bon de commande</h1>
        <div><strong>N° {{ $purchase->number }}</strong> · {{ $date($purchase->ordered_at ?? $purchase->created_at) }}</div>
        @if ($purchase->title)<div><strong>Objet :</strong> {{ $purchase->title }}</div>@endif
        @if ($purchase->project)<div class="muted">Projet : {{ $purchase->project->name }}</div>@endif
        @if ($purchase->expected_at)<div class="muted">Livraison souhaitée le {{ $date($purchase->expected_at) }}</div>@endif
    </td>
    <td>
        <div class="box">
            <strong>{{ $purchase->provider?->name }}</strong><br>
            @if ($purchase->provider?->contact_name){{ $purchase->provider->contact_name }}<br>@endif
            @if ($purchase->provider?->address)<span class="pre">{{ $purchase->provider->address }}</span><br>@endif
            @if ($purchase->provider?->phone){{ $purchase->provider->phone }}<br>@endif
            @if ($purchase->provider?->email){{ $purchase->provider->email }}@endif
        </div>
    </td>
</tr></table>

<table class="lines">
    <thead><tr>
        <th>Désignation</th>
        <th style="width:20%">Référence</th>
        <th class="center" style="width:11%">Quantité</th>
        <th class="num" style="width:15%">Prix unitaire</th>
        <th class="num" style="width:16%">Montant</th>
    </tr></thead>
    <tbody>
    @foreach ($purchase->lines as $line)
        <tr>
            <td class="pre">{{ $line->designation }}</td>
            <td class="ref pre">{{ $line->reference }}</td>
            <td class="center">{{ $qty($line->quantity) }}@if ($line->unit) {{ $line->unit }}@endif</td>
            <td class="num">{{ $money($line->unit_cost) }}</td>
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
            <tr><td>Total hors taxes</td><td class="num">{{ $money($purchase->subtotal) }} {{ $symbol }}</td></tr>
            @if ($purchase->tax_mode->value === 'vat')
                <tr><td>TVA ({{ $rate($purchase->vat_rate) }} %)</td><td class="num">{{ $money($purchase->tax_amount) }} {{ $symbol }}</td></tr>
                <tr class="grand"><td>Total TTC</td><td class="num">{{ $money($purchase->total) }} {{ $symbol }}</td></tr>
            @else
                <tr class="grand"><td>Total</td><td class="num">{{ $money($purchase->total) }} {{ $symbol }}</td></tr>
            @endif
        </table>
    </td>
</tr></table>
</body>
</html>
