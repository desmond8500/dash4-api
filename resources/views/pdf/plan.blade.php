<!DOCTYPE html>
<html lang="fr">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
<title>Plan — {{ $plan->name }}</title>
@include('pdf.partials.styles')
<style>
    h1 { font-size: 15pt; margin: 0 0 1mm; }
    h2 { font-size: 12pt; margin: 5mm 0 2mm; }
    .page-break { page-break-before: always; }
    /* Plan centré sur la page, horizontalement et verticalement */
    .plan-frame { width: 100%; height: {{ $landscape ? '150mm' : '232mm' }}; border-collapse: collapse; }
    .plan-frame td { text-align: center; vertical-align: middle; padding: 0; }
    .plan-frame img { max-width: 100%; max-height: {{ $landscape ? '148mm' : '230mm' }}; }
    table.list td, table.list th { font-size: 8.5px; padding: 1.2mm 1.5mm; }
</style>
</head>
<body>
@include('pdf.partials.header_footer', ['bank' => null])

<h1>{{ $plan->name }}</h1>
<p class="muted">
    {{ $plan->project?->name }}@if ($plan->project?->client) — {{ $plan->project->client->name }}@endif
    @if ($plan->building || $plan->level) · {{ collect([$plan->building?->name, $plan->level?->name])->filter()->join(' › ') }}@endif
    · édité le {{ now()->format('d/m/Y') }}
</p>

@forelse ($pages as $i => $img)
    <div class="sheet {{ $i > 0 ? 'page-break' : '' }}">
        @if (count($pages) > 1)<p class="muted">Page {{ $i + 1 }} / {{ count($pages) }}</p>@endif
        <table class="plan-frame"><tr><td><img src="{{ $img }}" alt=""></td></tr></table>
    </div>
@empty
    <p class="muted">Ce plan n'a pas encore d'aperçu : ouvrez-le dans l'éditeur de plans de Dash4, l'aperçu se crée tout seul.</p>
@endforelse

@if ($markers->isNotEmpty())
    <div class="page-break"></div>
    <h2>Repères ({{ $markers->count() }})</h2>
    <table class="list">
        <thead><tr><th>Système</th><th>Équipement / repère</th><th>Modèle</th><th>N° de série</th><th>Page</th></tr></thead>
        <tbody>
        @foreach ($markers as $m)
            @php($system = $m->installedSystem ?? $m->equipment?->installedSystem)
            <tr>
                <td>{{ $system?->name ?? '—' }}</td>
                <td>{{ $m->label ?: ($m->equipment?->name ?? 'Repère') }}@if ($m->label && $m->equipment) <span class="muted">({{ $m->equipment->name }})</span>@endif</td>
                <td>{{ $m->equipment?->model ?? '' }}</td>
                <td>{{ $m->equipment?->serial_number ?? '' }}</td>
                <td>{{ $m->page }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
@endif
</body>
</html>
