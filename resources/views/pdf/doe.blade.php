@php
    $place = fn ($e) => $e->room_id ? ($places['r'.$e->room_id] ?? null) : ($e->level_id ? ($places['l'.$e->level_id] ?? null) : null);
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
<title>DOE — {{ $project->name }}</title>
@include('pdf.partials.styles')
<style>
    h2 { font-size: 13pt; margin: 6mm 0 2mm; border-bottom: 1px solid #ddd; padding-bottom: 1mm; }
    h3 { font-size: 11pt; margin: 4mm 0 1.5mm; }
    .page-break { page-break-before: always; }
    .plan img { max-width: 100%; max-height: 230mm; }
    .chars { font-size: 8px; color: #374151; }
    .cover { margin-top: 40mm; text-align: center; }
    .cover h1 { font-size: 22pt; }
</style>
</head>
<body>
@include('pdf.partials.header_footer', ['bank' => null])

<div class="cover">
    <h1>Dossier des ouvrages exécutés</h1>
    <h2 style="border:0">{{ $project->name }}</h2>
    @if ($project->client)<p>Client : <strong>{{ $project->client->name }}</strong></p>@endif
    @if ($project->address)<p>{{ $project->address }}</p>@endif
    @if ($project->manager)<p>Chef de projet : {{ $project->manager->full_name }}</p>@endif
    <p class="muted">Édité le {{ now()->format('d/m/Y') }}</p>
</div>

<div class="page-break"></div>
<h2>Sommaire</h2>
<ol>
    <li>Bâtiments et locaux ({{ $project->buildings->count() }})</li>
    <li>Systèmes installés et équipements ({{ $project->installedSystems->count() }})</li>
    <li>Plans ({{ $project->plans->count() }})</li>
    <li>Fiches techniques ({{ $project->sheets->count() }})</li>
    <li>Procès-verbaux de réception ({{ $project->receptionReports->count() }})</li>
</ol>

<h2>1. Bâtiments et locaux</h2>
@forelse ($project->buildings as $b)
    <h3>{{ $b->name }}</h3>
    @if ($b->description)<div class="pre muted">{{ $b->description }}</div>@endif
    <ul>
    @foreach ($b->levels as $l)
        <li><strong>{{ $l->name }}</strong>@if ($l->rooms->isNotEmpty()) : {{ $l->rooms->pluck('name')->join(', ') }}@endif</li>
    @endforeach
    </ul>
@empty
    <p class="muted">Aucun bâtiment renseigné.</p>
@endforelse

<h2>2. Systèmes installés et équipements</h2>
@forelse ($project->installedSystems as $s)
    <h3>{{ $s->name }}@if ($s->building) — {{ $s->building->name }}@endif <span class="muted">({{ $s->progress }} %)</span></h3>
    @if ($s->description)<div class="pre">{{ $s->description }}</div>@endif
    <table class="lines">
        <thead><tr><th>Équipement</th><th style="width:7%">Qté</th><th style="width:20%">Modèle / n° de série</th><th style="width:22%">Emplacement</th><th style="width:25%">Caractéristiques</th></tr></thead>
        <tbody>
        @php($tops = $s->equipment->whereNull('parent_id'))
        @forelse ($tops as $e)
            @foreach ([$e, ...$s->equipment->where('parent_id', $e->id)->values()->all()] as $item)
            <tr>
                <td>@if ($item->parent_id)&nbsp;&nbsp;› @endif<strong>{{ $item->name }}</strong>@if ($item->type)<div class="ref">{{ $item->type }}</div>@endif</td>
                <td>{{ $item->quantity }}</td>
                <td>{{ $item->model }}@if ($item->serial_number)<div class="ref">N° {{ $item->serial_number }}</div>@endif</td>
                <td>{{ $place($item) }}</td>
                <td class="chars">@foreach ($item->characteristics ?? [] as $c){{ $c['label'] ?? '' }} : {{ $c['value'] ?? '' }}<br>@endforeach</td>
            </tr>
            @endforeach
        @empty
            <tr><td colspan="5" class="muted">Aucun équipement.</td></tr>
        @endforelse
        </tbody>
    </table>
    @php($docs = $s->documents->merge($s->equipment->flatMap->documents))
    @if ($docs->isNotEmpty())
        <p class="muted" style="font-size:8px">Documents : {{ $docs->pluck('name')->join(' · ') }}</p>
    @endif
@empty
    <p class="muted">Aucun système renseigné.</p>
@endforelse

<h2>3. Plans</h2>
@forelse ($project->plans as $p)
    <div class="{{ isset($planImages[$p->id]) ? 'plan page-break' : '' }}">
        <h3>{{ $p->name }} <span class="muted">{{ collect([$p->building?->name, $p->level?->name])->filter()->join(' › ') }}</span></h3>
        @if (isset($planImages[$p->id]))
            <img src="{{ $planImages[$p->id] }}" alt="">
            <p class="muted">{{ $p->markers->count() }} équipement(s) repéré(s) sur ce plan dans Dash4.</p>
        @else
            <p class="muted">Plan {{ strtoupper($p->kind) }} fourni à part ({{ $p->markers->count() }} repère(s)).</p>
        @endif
    </div>
@empty
    <p class="muted">Aucun plan.</p>
@endforelse

<h2 class="page-break">4. Fiches techniques</h2>
@forelse ($project->sheets as $sheet)
    <h3>{{ $sheet->title }} <span class="muted">{{ $sheet->type }}</span></h3>
    <table class="lines">
        <thead><tr><th style="width:12%">N°</th><th>Zone</th><th style="width:18%">Code</th><th style="width:30%">Équipement</th></tr></thead>
        <tbody>
        @foreach ($sheet->zones as $z)
            <tr><td>{{ $z->number }}</td><td>{{ $z->name }}</td><td>{{ $z->code }}</td><td>{{ $z->equipment_label }}</td></tr>
        @endforeach
        </tbody>
    </table>
@empty
    <p class="muted">Aucune fiche.</p>
@endforelse

<h2>5. Procès-verbaux de réception</h2>
@forelse ($project->receptionReports as $r)
    <p><strong>{{ $r->title }}</strong> — {{ $r->date?->format('d/m/Y') }} — {{ $r->status->label() }}
        @if (! empty($r->reserves)) ({{ count($r->reserves) }} réserve(s), {{ collect($r->reserves)->filter(fn ($x) => ! empty($x['lifted_at']))->count() }} levée(s))@endif</p>
@empty
    <p class="muted">Aucun procès-verbal.</p>
@endforelse
</body>
</html>
