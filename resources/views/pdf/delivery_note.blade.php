@php
    $qty = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, ',', ' '), '0'), ',');
    $date = fn ($d) => $d?->format('d/m/Y');
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
<title>{{ $note->number }}</title>
@include('pdf.partials.styles')
</head>
<body>
@include('pdf.partials.header_footer', ['bank' => null, 'showLogo' => $note->show_logo])

<table class="meta"><tr>
    <td style="width:55%">
        <h1>{{ $note->type->label() }}</h1>
        <div><strong>N° {{ $note->number }}</strong> · {{ $date($note->delivered_at) }}</div>
        <div><strong>Objet :</strong> {{ $note->title }}</div>
        <div class="muted">Devis {{ $quote->number }}@if ($quote->project) · Projet : {{ $quote->project->name }}@endif</div>
    </td>
    <td>
        <div class="box">
            <strong>{{ $quote->client?->name }}</strong><br>
            @if ($quote->client?->address)<span class="pre">{{ $quote->client->address }}</span><br>@endif
            @if ($quote->client?->phones->first()){{ $quote->client->phones->first()->number }}@endif
        </div>
    </td>
</tr></table>

@if ($note->lines->isNotEmpty())
<table class="lines">
    <thead><tr>
        <th style="width:6%">#</th>
        <th>Désignation</th>
        <th style="width:30%">Référence</th>
        <th class="center" style="width:13%">Quantité</th>
    </tr></thead>
    <tbody>
    @foreach ($note->lines as $i => $line)
        <tr>
            <td>{{ $i + 1 }}</td>
            <td class="pre">{{ $line->designation }}</td>
            <td class="ref pre">{{ $line->reference }}</td>
            <td class="center">{{ $qty($line->quantity) }}@if ($line->unit) {{ $line->unit }}@endif</td>
        </tr>
    @endforeach
    </tbody>
</table>
@endif

@if ($note->done)<div class="avoid-break" style="margin-top:5mm"><strong>Travaux réalisés</strong><div class="pre">{{ $note->done }}</div></div>@endif
@if ($note->todo)<div class="avoid-break" style="margin-top:4mm"><strong>Reste à faire</strong><div class="pre">{{ $note->todo }}</div></div>@endif

<table class="avoid-break" style="margin-top:10mm"><tr>
    <td style="width:48%; vertical-align:top" class="box">
        <strong>Pour {{ $company->name }}</strong>
        <div class="signs center" style="margin-top:2mm">
            @if ($signature)<img src="{{ $signature }}" alt="">@endif
            @if ($stamp)<img src="{{ $stamp }}" alt="">@endif
        </div>
    </td>
    <td style="width:4%"></td>
    <td class="box" style="height:32mm; vertical-align:top">
        <strong>Reçu par le client</strong> — date, nom, signature et cachet
    </td>
</tr></table>
</body>
</html>
