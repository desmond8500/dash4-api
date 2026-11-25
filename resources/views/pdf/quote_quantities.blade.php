@php
    $qty = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, ',', ' '), '0'), ',');
    $date = fn ($d) => $d?->format('d/m/Y');
    $sections = $quantities->sections;
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
<title>{{ $quantities->filename() }}</title>
@include('pdf.partials.styles')
<style>
    h2 { font-size: 13px; color: #1e3a8a; margin: 6mm 0 1mm; border-bottom: 1px solid #c7d2fe; padding-bottom: 1mm; }
</style>
</head>
<body>
@include('pdf.partials.header_footer', ['bank' => null, 'neutral' => $neutral])

<h1>Quantitatif{{ $quantities->section ? ' — '.$quantities->section->title : '' }}</h1>
<div class="muted">Devis {{ $quote->number }} · {{ $date($quote->issued_at) }} · sans les forfaits et la main-d'œuvre</div>
<div style="margin-top:3mm"><strong>Objet :</strong> {{ $quote->title }}</div>
@if ($quote->client)<div><strong>Client :</strong> {{ $quote->client->name }}</div>@endif
@if ($quote->project)<div><strong>Projet :</strong> {{ $quote->project->name }}</div>@endif

@if ($sections->isEmpty())
    <p class="muted" style="margin-top:6mm">Aucune ligne de matériel dans {{ $quantities->section ? 'cette section' : 'ce devis' }}.</p>
@endif

@foreach ($sections as $index => $section)
    @if (! $quantities->section)<h2>{{ $index + 1 }}. {{ $section->title }}</h2>@endif
    <table class="lines" @if ($quantities->section) style="margin-top:5mm" @endif>
        <thead><tr>
            <th style="width:6%">#</th>
            <th>Désignation</th>
            <th style="width:26%">Référence</th>
            <th style="width:16%">Catégorie</th>
            <th class="center" style="width:13%">Quantité</th>
        </tr></thead>
        <tbody>
        @foreach ($section->lines as $i => $line)
            <tr>
                <td>{{ $i + 1 }}</td>
                <td class="pre">{{ $line->designation }}</td>
                <td class="ref pre">{{ $line->reference }}</td>
                <td>{{ $line->category->label() }}</td>
                <td class="center">{{ $qty($line->quantity) }}@if ($line->unit) {{ $line->unit }}@endif</td>
            </tr>
        @endforeach
        </tbody>
    </table>
@endforeach

@if ($sections->count() > 1)
    <h2>Récapitulatif (toutes sections)</h2>
    <table class="lines">
        <thead><tr>
            <th style="width:6%">#</th>
            <th>Désignation</th>
            <th style="width:26%">Référence</th>
            <th style="width:16%">Catégorie</th>
            <th class="center" style="width:13%">Quantité</th>
        </tr></thead>
        <tbody>
        @foreach ($quantities->summary() as $i => $row)
            <tr>
                <td>{{ $i + 1 }}</td>
                <td class="pre">{{ $row['designation'] }}</td>
                <td class="ref pre">{{ $row['reference'] }}</td>
                <td>{{ $row['category'] }}</td>
                <td class="center">{{ $qty($row['quantity']) }}@if ($row['unit']) {{ $row['unit'] }}@endif</td>
            </tr>
        @endforeach
        </tbody>
    </table>
@endif
</body>
</html>
