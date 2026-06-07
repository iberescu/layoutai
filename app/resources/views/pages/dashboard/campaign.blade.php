@extends('layouts.app')
@php $heading = $campaign->name; @endphp

@section('content')
@php
    $total  = $scoreStats['total']  ?? 0;
    $scored = $scoreStats['scored'] ?? 0;
    $avg    = $scoreStats['avg']    ?? null;
    $top    = $scoreStats['top']    ?? null;
    $scoredPct = $total > 0 ? round($scored / $total * 100) : 0;
@endphp

{{-- Scoring summary band --}}
<div class="bg-surface border border-line rounded-2xl p-5 mb-5 grid grid-cols-2 md:grid-cols-4 gap-4">
    <div>
        <p class="text-xs text-muted uppercase tracking-wide font-semibold">Variants</p>
        <p class="text-2xl font-bold mt-0.5" style="font-variant-numeric: tabular-nums;">{{ $total }}</p>
    </div>
    <div>
        <p class="text-xs text-muted uppercase tracking-wide font-semibold">Scored by Gemini</p>
        <p class="text-2xl font-bold mt-0.5" style="font-variant-numeric: tabular-nums;">
            {{ $scored }}
            <span class="text-base text-muted font-medium">/ {{ $total }}</span>
        </p>
        @if($total > 0)
            <div class="mt-1 h-1 rounded-full bg-bgmain overflow-hidden">
                <div class="h-full bg-accent" style="width: {{ $scoredPct }}%;"></div>
            </div>
        @endif
    </div>
    <div>
        <p class="text-xs text-muted uppercase tracking-wide font-semibold">Avg score</p>
        <p class="text-2xl font-bold mt-0.5" style="font-variant-numeric: tabular-nums;">
            {{ $avg !== null ? number_format($avg, 1) : '—' }}
        </p>
    </div>
    <div>
        <p class="text-xs text-muted uppercase tracking-wide font-semibold">Top score</p>
        <p class="text-2xl font-bold mt-0.5" style="font-variant-numeric: tabular-nums; color: {{ $top !== null && $top >= 75 ? '#10B981' : '#0F172A' }};">
            {{ $top !== null ? number_format($top, 1) : '—' }}
        </p>
    </div>
</div>

<div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
    @foreach(['draft' => 'Draft', 'ready' => 'Ready', 'running' => 'Running', 'paused' => 'Paused'] as $key => $label)
        <div class="bg-surface border border-line rounded-xl p-3 text-center">
            <p class="text-xs text-muted">{{ $label }}</p>
            <p class="text-xl font-bold" style="font-variant-numeric: tabular-nums;">{{ $counts[$key] ?? 0 }}</p>
        </div>
    @endforeach
</div>

<form method="GET" class="flex flex-wrap items-center gap-2 mb-4">
    <select name="status" class="rounded-xl border-line text-sm">
        <option value="">All statuses</option>
        @foreach(['generated','needs_review','approved','rejected','scheduled','running','winner','archived'] as $s)
            <option value="{{ $s }}" @selected(request('status') === $s)>{{ ucwords(str_replace('_',' ', $s)) }}</option>
        @endforeach
    </select>
    <select name="size" class="rounded-xl border-line text-sm">
        <option value="">All sizes</option>
        <optgroup label="Display">
            @foreach(['300x250','336x280','970x250','160x600','300x600','320x100','468x60','250x250'] as $s)
                <option value="{{ $s }}" @selected(request('size') === $s)>{{ $s }}</option>
            @endforeach
        </optgroup>
        <optgroup label="Social">
            @foreach(['1080x1080','1080x1350','1080x1920','1200x630'] as $s)
                <option value="{{ $s }}" @selected(request('size') === $s)>{{ $s }}</option>
            @endforeach
        </optgroup>
    </select>
    <select name="style" class="rounded-xl border-line text-sm">
        <option value="">All styles</option>
        @foreach(['standard' => 'Standard','animated' => 'Animated','creative' => 'Creative','social' => 'Social','showcase' => 'Showcase ★'] as $k => $v)
            <option value="{{ $k }}" @selected(request('style') === $k)>{{ $v }}</option>
        @endforeach
    </select>
    <select name="sort" class="rounded-xl border-line text-sm">
        <option value="" @selected(($sort ?? '') === '')>Sort: order created</option>
        <option value="score" @selected(($sort ?? '') === 'score')>Sort: creative score ↓</option>
    </select>
    <button class="rounded-xl bg-primary text-white px-4 py-2 text-sm">Filter</button>
</form>

{{-- Column-based masonry: same approach as /preview. Each tile preserves
     its true aspect ratio + the per-ad score badge floats top-right.
     Tiles pack top-to-bottom inside each column — no row-stretching
     whitespace between a leaderboard and a skyscraper in the same row. --}}
<div class="columns-1 sm:columns-2 lg:columns-3 xl:columns-4 gap-4 [&_.tile]:mb-4">
    @foreach($variants as $i => $variant)
        @php
            $score      = $variant->creative_score !== null ? (float) $variant->creative_score : null;
            $scoreColor = match(true) {
                $score === null => null,
                $score >= 75    => '#10B981',
                $score >= 50    => '#2563EB',
                $score >= 25    => '#F59E0B',
                default         => '#94A3B8',
            };
            $rationale = $variant->creative_score_meta['rationale'] ?? null;
        @endphp
        <div class="tile group relative break-inside-avoid bg-surface border border-line overflow-hidden shadow-sm hover:shadow-xl hover:-translate-y-1 transition-all duration-300"
             style="animation: tileIn 0.5s cubic-bezier(0.22, 1, 0.36, 1) both; animation-delay: {{ min($i * 28, 600) }}ms;"
             data-variant-id="{{ $variant->id }}" data-ad-w="{{ $variant->size_width }}" data-ad-h="{{ $variant->size_height }}">
            @if($score !== null)
                <div class="absolute top-2 right-2 z-10 inline-flex items-center gap-1.5 px-2 py-1 rounded-full bg-ink/85 backdrop-blur text-white shadow-sm"
                     @if($rationale) title="{{ $rationale }}" @endif>
                    <span class="w-1.5 h-1.5 rounded-full" style="background: {{ $scoreColor }};"></span>
                    <span class="text-xs font-bold" style="font-variant-numeric: tabular-nums;">{{ number_format($score, 0) }}</span>
                </div>
            @else
                <div class="absolute top-2 right-2 z-10 inline-flex items-center gap-1.5 px-2 py-1 rounded-full bg-bgmain border border-line text-muted">
                    <span class="w-1.5 h-1.5 rounded-full bg-muted/50 animate-pulse"></span>
                    <span class="text-[10px] font-semibold uppercase tracking-wide">Scoring</span>
                </div>
            @endif

            @php
                $styleBadge = match($variant->style) {
                    'animated' => ['Animated',  'bg-primary/15 text-primary'],
                    'creative' => ['Creative',  'bg-accent/15 text-accent'],
                    'social'   => ['Social',    'bg-success/15 text-success'],
                    'showcase' => ['★ Showcase', 'bg-gradient-to-r from-primary to-accent text-white'],
                    'template' => ['Template',  'bg-ink/10 text-ink'],
                    'product'  => ['Shop',      'bg-success/15 text-success'],
                    default    => null,
                };
            @endphp
            @if($styleBadge)
                <span class="absolute top-2 left-2 z-10 inline-flex items-center gap-1 px-1.5 py-0.5 rounded-md text-[10px] font-bold uppercase tracking-wide backdrop-blur-sm {{ $styleBadge[1] }}">
                    {{ $styleBadge[0] }}
                </span>
            @endif
            <div class="relative bg-bgmain" style="aspect-ratio: {{ $variant->size_width }}/{{ $variant->size_height }};">
                @if($variant->html)
                    <iframe data-ad-frame
                            srcdoc="{{ $variant->html }}"
                            width="{{ $variant->size_width }}"
                            height="{{ $variant->size_height }}"
                            loading="lazy"
                            sandbox=""
                            scrolling="no"
                            class="absolute top-0 left-0 border-0 pointer-events-none"
                            style="width: {{ $variant->size_width }}px; height: {{ $variant->size_height }}px;"></iframe>
                @else
                    <div class="absolute inset-0" style="background: linear-gradient(135deg, {{ $brand?->primaryColor() ?? '#2563EB' }}, {{ $brand?->accentColor() ?? '#7C3AED' }});"></div>
                    <div class="absolute inset-0 p-3 text-white flex flex-col justify-end">
                        <p class="font-bold text-sm">{{ $variant->headline }}</p>
                        <p class="text-xs opacity-80">{{ $variant->cta }}</p>
                    </div>
                @endif
            </div>
            <div class="px-3 py-2.5 text-xs flex items-center justify-between gap-2">
                <span class="text-muted font-medium" style="font-variant-numeric: tabular-nums;">{{ $variant->size_width }}<span class="text-muted/50 mx-0.5">×</span>{{ $variant->size_height }}</span>
                <span class="px-1.5 py-0.5 rounded-md text-[10px] font-semibold uppercase tracking-wide bg-bgmain text-muted">{{ str_replace('_',' ',$variant->status) }}</span>
            </div>
        </div>
    @endforeach
</div>

<div class="mt-6">{{ $variants->withQueryString()->links() }}</div>

<style>
    @keyframes tileIn {
        from { opacity: 0; transform: translateY(12px) scale(0.98); }
        to   { opacity: 1; transform: translateY(0) scale(1); }
    }
</style>

<script>
function scaleAdFrame(tile) {
    const frame = tile.querySelector('[data-ad-frame]');
    if (!frame) return;
    const adW = parseInt(tile.dataset.adW || '0', 10);
    if (!adW) return;
    const box = frame.parentElement;
    const tileW = box ? box.clientWidth : tile.clientWidth;
    if (!tileW) return;
    frame.style.transformOrigin = '0 0';
    frame.style.transform = `scale(${tileW / adW})`;
}
function scaleAdFrames() {
    document.querySelectorAll('[data-variant-id]').forEach(scaleAdFrame);
}
function watchAdFrames() {
    if (typeof ResizeObserver === 'undefined') return;
    const ro = new ResizeObserver(entries => {
        for (const e of entries) {
            const tile = e.target.closest('[data-variant-id]');
            if (tile) scaleAdFrame(tile);
        }
    });
    document.querySelectorAll('[data-variant-id] > .relative').forEach(el => ro.observe(el));
}
scaleAdFrames();
requestAnimationFrame(scaleAdFrames);
window.addEventListener('load', () => { scaleAdFrames(); watchAdFrames(); });
window.addEventListener('resize', scaleAdFrames);
watchAdFrames();
</script>
@endsection
