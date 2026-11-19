@php
    $date = fn ($d) => $d?->format('d/m/Y');
    $time = fn ($d) => $d?->format('H:i');
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
<title>Rapport d'intervention — {{ $journal->title }}</title>
@include('pdf.partials.styles')
<style>
    .section { margin-top: 5mm; }
    .section h3 { margin: 0 0 1.5mm; font-size: 11pt; border-bottom: 1px solid #ddd; padding-bottom: 1mm; }
    .photos td { width: 33%; padding: 1.5mm; vertical-align: top; text-align: center; }
    .photos img { max-width: 100%; max-height: 55mm; }
    .caption { font-size: 7.5pt; color: #666; }
</style>
</head>
<body>
@include('pdf.partials.header_footer', ['bank' => null])

<table class="meta"><tr>
    <td style="width:55%">
        <h1>Rapport d'intervention</h1>
        <div><strong>{{ $journal->title }}</strong></div>
        <div>{{ $journal->type->label() }} · {{ $date($journal->date) }}@if ($journal->starts_at) · {{ $time($journal->starts_at) }}@if ($journal->ends_at) – {{ $time($journal->ends_at) }}@endif @endif</div>
        @if ($journal->project)<div class="muted">Projet : {{ $journal->project->name }}</div>@endif
    </td>
    <td>
        @if ($journal->client)
        <div class="box">
            <strong>{{ $journal->client->name }}</strong><br>
            @if ($journal->client->address)<span class="pre">{{ $journal->client->address }}</span>@endif
        </div>
        @endif
    </td>
</tr></table>

@if ($journal->participants->isNotEmpty())
<div class="section">
    <h3>Intervenants</h3>
    @foreach ($journal->participants as $p){{ $p->displayName() }}@if ($p->role) ({{ $p->role }})@endif{{ $loop->last ? '' : ' · ' }}@endforeach
</div>
@endif

@if ($journal->summary)
<div class="section"><h3>Résumé</h3><div class="pre">{{ $journal->summary }}</div></div>
@endif

@foreach ($journal->sections as $section)
<div class="section avoid-break">
    <h3>{{ $section->title }}</h3>
    @if ($section->body)<div class="pre">{{ $section->body }}</div>@endif
</div>
@endforeach

@if ($photos->isNotEmpty())
<div class="section">
    <h3>Photos</h3>
    <table class="photos">
        @foreach ($photos->chunk(3) as $row)
        <tr>
            @foreach ($row as $photo)
            <td><img src="{{ $photo['src'] }}" alt=""><div class="caption">{{ $photo['name'] }}</div></td>
            @endforeach
        </tr>
        @endforeach
    </table>
</div>
@endif

<p class="muted" style="margin-top:8mm">Rapport rédigé par {{ $journal->creator?->full_name ?? '—' }}.</p>
</body>
</html>
