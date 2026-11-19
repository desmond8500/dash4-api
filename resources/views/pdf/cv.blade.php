<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>CV — {{ $cv->fullname ?: $cv->name }}</title>
<style>
    @page { margin: 14mm 14mm; }
    body { font-family: '{{ $font }}', sans-serif; font-size: 9.5pt; color: #222; line-height: 1.35; }
    h1 { font-size: 20pt; margin: 0; color: {{ $cv->accent_color }}; }
    .title { font-size: 11pt; color: #555; margin-top: 2px; }
    .contact { font-size: 8.5pt; color: #555; margin-top: 6px; }
    .head { width: 100%; border-bottom: 2px solid {{ $cv->accent_color }}; padding-bottom: 8px; margin-bottom: 10px; }
    .photo { width: 26mm; height: 26mm; border-radius: 13mm; object-fit: cover; }
    h2 { font-size: 10.5pt; text-transform: uppercase; letter-spacing: 0.5px; color: {{ $cv->accent_color }}; border-bottom: 1px solid #ddd; padding-bottom: 2px; margin: 12px 0 6px; }
    .item { margin-bottom: 7px; page-break-inside: avoid; }
    .item-title { font-weight: bold; }
    .muted { color: #666; font-size: 8.5pt; }
    .dates { float: right; color: #666; font-size: 8.5pt; }
    .desc { margin-top: 2px; white-space: pre-line; }
    .dot { display: inline-block; width: 6px; height: 6px; border-radius: 3px; margin-right: 2px; background: #ddd; }
    .dot.on { background: {{ $cv->accent_color }}; }
    table.cols { width: 100%; border-collapse: collapse; }
    td.main { vertical-align: top; padding-right: 12px; }
    td.aside { vertical-align: top; width: 32%; padding-left: 12px; border-left: 1px solid #eee; }
</style>
</head>
<body>
@php
    $dates = function ($item) {
        $end = $item->current ? 'aujourd’hui' : $item->end_date;
        return trim(($item->start_date ?? '').($item->start_date && $end ? ' – ' : '').($end ?? ''));
    };
@endphp
<table class="head">
    <tr>
        @if ($photo)
            <td style="width: 30mm; vertical-align: middle;"><img class="photo" src="{{ $photo }}" alt=""></td>
        @endif
        <td style="vertical-align: middle;">
            <h1>{{ $cv->fullname ?: $cv->name }}</h1>
            @if ($cv->title)
                <div class="title">{{ $cv->title }}</div>
            @endif
            <div class="contact">
                {{ collect([$cv->email, $cv->phone, $cv->address, $cv->website])->filter()->implode('  ·  ') }}
            </div>
        </td>
    </tr>
</table>

@if ($cv->summary)
    <div class="desc">{{ $cv->summary }}</div>
@endif

@if ($two)
<table class="cols"><tr><td class="main">
@endif

@foreach ($main as $section)
    <h2>{{ $section->name }}</h2>
    @foreach ($section->items as $item)
        <div class="item">
            @if ($dates($item) !== '')
                <span class="dates">{{ $dates($item) }}</span>
            @endif
            <div class="item-title">{{ $item->title }}</div>
            @if ($item->subtitle || $item->place)
                <div class="muted">{{ collect([$item->subtitle, $item->place])->filter()->implode(' — ') }}</div>
            @endif
            @if ($item->level)
                <div>@for ($i = 1; $i <= 5; $i++)<span class="dot {{ $i <= $item->level ? 'on' : '' }}"></span>@endfor</div>
            @endif
            @if ($item->description)
                <div class="desc">{{ $item->description }}</div>
            @endif
        </div>
    @endforeach
@endforeach

@if ($two)
</td><td class="aside">
@foreach ($aside as $section)
    <h2>{{ $section->name }}</h2>
    @foreach ($section->items as $item)
        <div class="item">
            <div class="item-title">{{ $item->title }}</div>
            @if ($item->subtitle)
                <div class="muted">{{ $item->subtitle }}</div>
            @endif
            @if ($item->level)
                <div>@for ($i = 1; $i <= 5; $i++)<span class="dot {{ $i <= $item->level ? 'on' : '' }}"></span>@endfor</div>
            @endif
            @if ($item->description)
                <div class="desc">{{ $item->description }}</div>
            @endif
        </div>
    @endforeach
@endforeach
</td></tr></table>
@endif
</body>
</html>
