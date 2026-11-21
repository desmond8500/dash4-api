<!DOCTYPE html>
<html lang="fr">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
<title>{{ $sheet->title }}</title>
@include('pdf.partials.styles')
</head>
<body>
@include('pdf.partials.header_footer', ['bank' => null])
<h1>{{ $sheet->title }}</h1>
<div class="muted">
    {{ $sheet->type }}@if ($sheet->type && $sheet->date) · @endif{{ $sheet->date?->format('d/m/Y') }}
    · Projet : {{ $sheet->project->name }}@if ($sheet->project->client) — {{ $sheet->project->client->name }}@endif
    @if ($sheet->building) · {{ $sheet->building->name }}@endif
    @if ($sheet->installedSystem) · {{ $sheet->installedSystem->name }}@endif
</div>
@if ($sheet->contact_name || $sheet->contact_phone || $sheet->contact_email)
<p><strong>Contact :</strong> {{ $sheet->contact_name }} {{ $sheet->contact_phone }} {{ $sheet->contact_email }}</p>
@endif
<table class="lines" style="margin-top:4mm">
    <thead><tr><th style="width:10%">N°</th><th>Zone</th><th style="width:16%">Code</th><th style="width:24%">Équipement</th><th style="width:22%">Emplacement</th></tr></thead>
    <tbody>
    @forelse ($sheet->zones as $z)
        @php($room = $z->room_id ? ($rooms[$z->room_id] ?? null) : null)
        <tr>
            <td><strong>{{ $z->number }}</strong></td>
            <td>{{ $z->name }}</td>
            <td>{{ $z->code }}</td>
            <td>{{ $z->equipment_label }}</td>
            <td>{{ $room ? ($room->level?->name.' › '.$room->name) : '' }}</td>
        </tr>
    @empty
        <tr><td colspan="5" class="muted">Aucune zone.</td></tr>
    @endforelse
    </tbody>
</table>
@if ($sheet->notes)<div class="md" style="margin-top:4mm">@markdown($sheet->notes)</div>@endif
</body>
</html>
