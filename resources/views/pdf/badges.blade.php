<!DOCTYPE html>
<html lang="fr">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
<title>Badges</title>
<style>
    @page { margin: {{ $layout === 'sheet' ? '10mm 12mm' : '0' }}; }
    * { box-sizing: border-box; }
    body { margin: 0; font-family: DejaVu Sans, sans-serif; }
    .card { position: relative; overflow: hidden; width: 85.6mm; height: 54mm; background: #fff; }
    .card.portrait { width: 54mm; height: 85.6mm; }
    .sheet .card { display: inline-block; margin: 0 4mm 4mm 0; border: 0.2mm dashed #9ca3af; vertical-align: top; }
    .page-break { page-break-after: always; }
    .bg { position: absolute; left: 0; top: 0; width: 100%; height: 100%; }
    .band { position: absolute; left: 0; top: 0; right: 0; height: 11mm; color: #fff; padding: 1.5mm 3mm; }
    .band .t { font-size: 8pt; font-weight: bold; }
    .band .s { font-size: 6pt; }
    .band img { position: absolute; right: 2mm; top: 1.5mm; max-height: 8mm; max-width: 20mm; }
    .photo { position: absolute; left: 3mm; top: 14mm; width: 22mm; height: 28mm; border: 0.3mm solid #d1d5db; background: #f3f4f6; }
    .photo img { width: 22mm; height: 28mm; }
    .info { position: absolute; left: 28mm; right: 3mm; top: 14mm; font-size: 7pt; }
    .portrait .photo { left: 16mm; top: 14mm; }
    .portrait .info { left: 3mm; right: 3mm; top: 45mm; text-align: center; }
    .name { font-size: 10pt; font-weight: bold; margin-bottom: 1mm; }
    .row { margin-bottom: 0.6mm; }
    .row span { color: #6b7280; font-size: 5.5pt; text-transform: uppercase; }
    .foot { position: absolute; left: 0; right: 0; bottom: 0; height: 5mm; font-size: 5.5pt; color: #fff; text-align: center; padding-top: 1.2mm; }
</style>
</head>
<body class="{{ $layout }}">
@foreach ($cards as $i => $c)
    @php($b = $c['badge'])
    <div class="card {{ $c['portrait'] ? 'portrait' : '' }}" style="color: {{ $c['text'] }}">
        @if ($c['background'])<img class="bg" src="{{ $c['background'] }}" alt="">@endif
        <div class="band" style="background: {{ $c['primary'] }}">
            <div class="t">{{ $c['title'] }}</div>
            @if ($c['subtitle'])<div class="s">{{ $c['subtitle'] }}</div>@endif
            @if ($c['logo'])<img src="{{ $c['logo'] }}" alt="">@endif
        </div>
        <div class="photo">@if ($c['photo'])<img src="{{ $c['photo'] }}" alt="">@endif</div>
        <div class="info">
            <div class="name">{{ $b->firstname }} {{ mb_strtoupper((string) $b->lastname) }}</div>
            @foreach ($c['fields'] as $field)
                @php($value = $field === 'valid_until' ? $b->valid_until?->format('d/m/Y') : $b->{$field})
                @if ($value)<div class="row"><span>{{ $labels[$field] }}</span><br>{{ $value }}</div>@endif
            @endforeach
        </div>
        @if ($c['footer'])<div class="foot" style="background: {{ $c['primary'] }}">{{ $c['footer'] }}</div>@endif
    </div>
    @if ($layout === 'cards' && ! $loop->last)<div class="page-break"></div>@endif
    @if ($layout === 'sheet' && $loop->iteration % 10 === 0 && ! $loop->last)<div class="page-break"></div>@endif
@endforeach
</body>
</html>
