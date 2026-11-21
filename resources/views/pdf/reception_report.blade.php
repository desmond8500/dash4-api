@php
    $date = fn ($d) => $d?->format('d/m/Y');
    $parties = collect(\App\Models\ReceptionReport::PARTIES)->filter(fn ($label, $key) => $report->{"{$key}_name"} || $logos[$key]);
    $reserves = collect($report->reserves ?? []);
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
<title>Procès-verbal de réception — {{ $report->title }}</title>
@include('pdf.partials.styles')
<style>
    .section { margin-top: 5mm; }
    .section h3 { margin: 0 0 1.5mm; font-size: 11pt; border-bottom: 1px solid #ddd; padding-bottom: 1mm; }
    .parties td { width: 25%; vertical-align: top; text-align: center; padding: 2mm; }
    .parties img { max-height: 16mm; max-width: 38mm; margin-bottom: 1mm; }
    .parties .role { font-size: 8px; text-transform: uppercase; color: #6b7280; }
    .sign-grid td { width: 50%; height: 32mm; vertical-align: top; border: 1px solid #e5e7eb; padding: 2mm; }
    .lifted { color: #059669; }
    .checkbox { display: inline-block; width: 3mm; height: 3mm; border: 1px solid #374151; margin-right: 2mm; }
</style>
</head>
<body>
@include('pdf.partials.header_footer', ['bank' => null])

<div class="center">
    <h1>Procès-verbal de réception</h1>
    <div><strong>{{ $report->title }}</strong></div>
    @if ($report->subtitle)<div class="muted">{{ $report->subtitle }}</div>@endif
    <div class="muted">
        Le {{ $date($report->date) }}@if ($report->location) — {{ $report->location }}@endif
        · Projet : {{ $report->project->name }}
        @if ($report->quote) · Devis {{ $report->quote->number }}@endif
    </div>
</div>

@if ($parties->isNotEmpty())
<table class="parties section"><tr>
    @foreach ($parties as $key => $label)
    <td>
        @if ($logos[$key])<img src="{{ $logos[$key] }}" alt=""><br>@endif
        <div class="role">{{ $label }}</div>
        <strong>{{ $report->{$key.'_name'} }}</strong>
    </td>
    @endforeach
</tr></table>
@endif

<div class="section">
    <h3>Ouvrages réceptionnés</h3>
    @if ($report->systems->isEmpty())
        <p class="muted">Ensemble des travaux du projet.</p>
    @else
    <table class="lines">
        <thead><tr><th>Système</th><th>Bâtiment</th><th>Équipements</th><th class="num">Avancement</th></tr></thead>
        <tbody>
        @foreach ($report->systems as $system)
            <tr>
                <td><strong>{{ $system->name }}</strong>@if ($system->description)<div class="ref md">@markdown($system->description)</div>@endif</td>
                <td>{{ $system->building?->name ?? '—' }}</td>
                <td>
                    @foreach ($system->equipment->whereNull('parent_id')->groupBy(fn ($e) => $e->type ?: $e->name) as $type => $items)
                        {{ $items->sum('quantity') }} × {{ $type }}{{ $loop->last ? '' : ', ' }}
                    @endforeach
                </td>
                <td class="num">{{ $system->progress }} %</td>
            </tr>
        @endforeach
        </tbody>
    </table>
    @endif
</div>

<div class="section avoid-break">
    <h3>Décision</h3>
    <p><span class="checkbox">{!! $report->status->value === 'accepted' || ($report->status->value === 'signed' && $reserves->isEmpty()) ? '✔' : '' !!}</span> Réception prononcée sans réserve</p>
    <p><span class="checkbox">{!! $report->status->value === 'with_reserves' || ($report->status->value === 'signed' && $reserves->isNotEmpty()) ? '✔' : '' !!}</span> Réception prononcée avec les réserves ci-dessous</p>
</div>

@if ($reserves->isNotEmpty())
<div class="section">
    <h3>Réserves</h3>
    <table class="lines">
        <thead><tr><th style="width:6%">N°</th><th>Réserve</th><th style="width:22%">Levée</th></tr></thead>
        <tbody>
        @foreach ($reserves as $i => $r)
            <tr>
                <td>{{ $i + 1 }}</td>
                <td class="pre">{{ $r['text'] ?? '' }}</td>
                <td>@if (! empty($r['lifted_at']))<span class="lifted">Levée le {{ \Illuminate\Support\Carbon::parse($r['lifted_at'])->format('d/m/Y') }}</span>@else — @endif</td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
@endif

@if ($report->observations)
<div class="section"><h3>Observations</h3><div class="md">@markdown($report->observations)</div></div>
@endif

<table class="sign-grid section avoid-break"><tr>
    <td>
        <div class="role muted">Pour l'entreprise — {{ $company->name }}</div>
        <div class="signs center" style="margin-top:2mm">
            @if ($signature)<img src="{{ $signature }}" alt="">@endif
            @if ($stamp)<img src="{{ $stamp }}" alt="">@endif
        </div>
    </td>
    <td>
        <div class="role muted">Pour le client{{ $report->client_name ? ' — '.$report->client_name : '' }}</div>
        <div class="muted" style="margin-top:20mm; font-size:8px">Date, nom, signature et cachet</div>
    </td>
</tr></table>
</body>
</html>
