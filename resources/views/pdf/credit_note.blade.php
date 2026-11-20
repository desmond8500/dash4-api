@php
    $money = fn ($v) => number_format((float) $v, 0, ',', ' ');
    $symbol = ['XOF' => 'F CFA', 'EUR' => '€', 'USD' => '$'][$currency] ?? $currency;
    $date = fn ($d) => $d?->format('d/m/Y');
    $invoice = $note->invoice;
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
<title>{{ $note->number }}</title>
@include('pdf.partials.styles')
</head>
<body>
@include('pdf.partials.header_footer', ['bank' => $invoice->bankAccount])

<table class="meta"><tr>
    <td style="width:55%">
        <h1>Avoir {{ $note->number }}</h1>
        <div><strong>Sur la facture {{ $invoice->number }}</strong> — {{ $invoice->title }}</div>
        @if ($invoice->project)<div class="muted">Projet : {{ $invoice->project->name }}</div>@endif
        <div class="muted">Date : {{ $date($note->issued_at) }} · Facture du {{ $date($invoice->issued_at) }}</div>
    </td>
    <td>
        <div class="box">
            <strong>{{ $note->client?->name }}</strong><br>
            @if ($note->client?->address)<span class="pre">{{ $note->client->address }}</span><br>@endif
            @if ($note->client?->phones->first()){{ $note->client->phones->first()->number }}<br>@endif
            @if ($note->client?->emails->first()){{ $note->client->emails->first()->address }}@endif
        </div>
    </td>
</tr></table>

<table class="lines" style="margin-top:6mm">
    <thead><tr><th>Motif</th><th class="num" style="width:22%">Montant</th></tr></thead>
    <tbody>
        <tr><td class="pre">{{ $note->reason }}</td><td class="num">{{ $money($note->amount) }} {{ $symbol }}</td></tr>
    </tbody>
</table>

<table class="avoid-break" style="margin-top:6mm"><tr>
    <td class="signs center" style="width:52%; vertical-align:middle">
        @if ($signature)<img src="{{ $signature }}" alt="">@endif
        @if ($stamp)<img src="{{ $stamp }}" alt="">@endif
    </td>
    <td>
        <table class="totals">
            <tr><td>Total de la facture {{ $invoice->number }}</td><td class="num">{{ $money($invoice->total) }} {{ $symbol }}</td></tr>
            <tr class="grand"><td>Montant de l'avoir</td><td class="num">− {{ $money($note->amount) }} {{ $symbol }}</td></tr>
            <tr><td>Reste à payer sur la facture</td><td class="num">{{ $money($invoice->remainingAmount()) }} {{ $symbol }}</td></tr>
        </table>
    </td>
</tr></table>
</body>
</html>
