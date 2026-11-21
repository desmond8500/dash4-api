@php
    $money = fn ($v) => number_format((float) $v, 0, ',', ' ');
    $symbol = ['XOF' => 'F CFA', 'EUR' => '€', 'USD' => '$'][$currency] ?? $currency;
    $date = fn ($d) => $d?->format('d/m/Y');
    $invoiced = $invoices->sum('total');
    $paid = $invoices->sum('paid_amount');
    $credited = $invoices->sum('credited_amount');
    $accepted = $quotes->filter(fn ($q) => $q->status->value === 'accepted')->sum('total');
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
<title>Résumé — {{ $project->name }}</title>
@include('pdf.partials.styles')
<style>
    .grid td { vertical-align: top; }
    .kpi { font-size: 13pt; font-weight: bold; }
    .bar { background: #e5e7eb; height: 6px; width: 100%; }
    .bar div { background: #1690b7; height: 6px; }
    h2 { font-size: 11pt; margin: 6mm 0 2mm; border-bottom: 1px solid #ddd; padding-bottom: 1mm; }
</style>
</head>
<body>
@include('pdf.partials.header_footer', ['bank' => null])

<table class="meta"><tr>
    <td style="width:55%">
        <h1>Résumé du projet</h1>
        <div><strong>{{ $project->name }}</strong></div>
        <div class="muted">
            Statut : {{ $project->status->label() }}
            @if ($project->start_date) · Début : {{ $date($project->start_date) }}@endif
            @if ($project->end_date) · Fin : {{ $date($project->end_date) }}@endif
        </div>
        @if ($project->manager)<div class="muted">Chef de projet : {{ $project->manager->full_name }}</div>@endif
        <div class="muted">Édité le {{ now()->format('d/m/Y') }}</div>
    </td>
    <td>
        <div class="box">
            <strong>{{ $project->client?->name }}</strong><br>
            @if ($project->address)<span class="pre">{{ $project->address }}</span><br>@endif
            @if ($project->client?->phones->first()){{ $project->client->phones->first()->number }}<br>@endif
            @if ($project->client?->emails->first()){{ $project->client->emails->first()->address }}@endif
        </div>
    </td>
</tr></table>

@if ($project->description)<div class="md" style="margin-top:4mm">@markdown($project->description)</div>@endif

@if ($prices)
<h2>Chiffres clés</h2>
<table class="grid" style="width:100%"><tr>
    <td><div class="muted">Devis acceptés</div><div class="kpi">{{ $money($accepted) }} {{ $symbol }}</div></td>
    <td><div class="muted">Facturé</div><div class="kpi">{{ $money($invoiced) }} {{ $symbol }}</div></td>
    <td><div class="muted">Encaissé</div><div class="kpi">{{ $money($paid) }} {{ $symbol }}</div></td>
    <td><div class="muted">Reste à encaisser</div><div class="kpi">{{ $money(max(0, $invoiced - $paid - $credited)) }} {{ $symbol }}</div></td>
    @if ($expenses !== null)<td><div class="muted">Dépenses</div><div class="kpi">{{ $money($expenses) }} {{ $symbol }}</div></td>@endif
</tr></table>
@endif

@if ($quotes->isNotEmpty())
<h2>Devis</h2>
<table class="lines">
    <thead><tr><th style="width:18%">Numéro</th><th>Titre</th><th style="width:14%">Date</th><th style="width:14%">Statut</th>@if ($prices)<th class="num" style="width:16%">Total</th>@endif</tr></thead>
    @foreach ($quotes as $q)
        <tr><td>{{ $q->number }}</td><td>{{ $q->title }}</td><td>{{ $date($q->issued_at) }}</td><td>{{ $q->status->label() }}</td>@if ($prices)<td class="num">{{ $money($q->total) }}</td>@endif</tr>
    @endforeach
</table>
@endif

@if ($invoices->isNotEmpty())
<h2>Factures</h2>
<table class="lines">
    <thead><tr><th style="width:18%">Numéro</th><th>Titre</th><th style="width:14%">Date</th><th style="width:14%">Statut</th><th class="num" style="width:16%">Total</th><th class="num" style="width:16%">Reste</th></tr></thead>
    @foreach ($invoices as $i)
        <tr><td>{{ $i->number }}</td><td>{{ $i->title }}</td><td>{{ $date($i->issued_at) }}</td><td>{{ $i->status->label() }}</td><td class="num">{{ $money($i->total) }}</td><td class="num">{{ $money($i->remainingAmount()) }}</td></tr>
    @endforeach
</table>
@endif

<h2>Tâches</h2>
<p>{{ $tasks['total'] }} tâche(s), dont {{ $tasks['done'] }} terminée(s)@if ($tasks['late']) et {{ $tasks['late'] }} en retard @endif.</p>

@if ($systems->isNotEmpty())
<h2>Avancement du chantier</h2>
<table class="lines">
    @foreach ($systems as $s)
        <tr><td style="width:50%">{{ $s->name }}</td><td><div class="bar"><div style="width: {{ (int) $s->progress }}%"></div></div></td><td class="num" style="width:12%">{{ (int) $s->progress }} %</td></tr>
    @endforeach
</table>
@endif

@if ($journals->isNotEmpty())
<h2>Derniers rapports</h2>
<table class="lines">
    @foreach ($journals as $j)
        <tr><td style="width:16%">{{ $date($j->date) }}</td><td>{{ $j->title }}</td><td style="width:20%">{{ $j->type->label() }}</td></tr>
    @endforeach
</table>
@endif
</body>
</html>
