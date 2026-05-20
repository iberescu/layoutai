@extends('layouts.marketing')

@section('content')
<section class="max-w-7xl mx-auto px-6 py-12"
    x-data="previewLive('{{ route('create.renders', $session->uuid) }}', {{ $variants->count() }})"
    x-init="start()">
    <div class="flex flex-col md:flex-row md:items-end justify-between gap-4 mb-10">
        <div>
            <span class="inline-flex items-center gap-2 px-3 py-1 rounded-full text-xs font-semibold mb-3 transition-colors"
                  :class="status === 'preview_ready' ? 'bg-success/10 text-success' : 'bg-primary/10 text-primary'">
                <span class="w-1.5 h-1.5 rounded-full"
                      :class="status === 'preview_ready' ? 'bg-success' : 'bg-primary animate-pulse'"></span>
                <span x-text="status === 'preview_ready' ? 'Preview ready' : `Generating · ${done}/${total}`"
                      style="font-variant-numeric: tabular-nums;"></span>
            </span>
            <h1 class="text-4xl md:text-5xl font-extrabold tracking-tight">Your first ads are ready.</h1>
            <p class="text-muted mt-2 max-w-2xl">We crawled your site, learned the brand, and generated 30 ads: 20 brand/product and 10 daily-event variants. Tiles fill in live as each design lands.</p>
        </div>
        <a href="{{ route('create.claim', $session->uuid) }}" class="bg-primary text-white font-semibold px-6 py-3.5 rounded-xl shadow-lg shadow-primary/20 hover:shadow-xl hover:-translate-y-0.5 transition whitespace-nowrap">Claim $500 credit</a>
    </div>

    @if($brand)
    @php
        $tone = $brand->brand_voice_json['tone'] ?? null;
        $ctas = array_filter($brand->ctas_json ?? []);
    @endphp
    <div class="bg-surface border border-line rounded-3xl p-6 md:p-8 mb-10 flex flex-col md:flex-row gap-6 items-start">
        @if($brand->logoAsset)
            <img src="{{ $brand->logoAsset->url() }}" alt="" class="w-20 h-20 rounded-2xl border border-line bg-bgmain object-contain p-2 shrink-0">
        @else
            <div class="w-20 h-20 rounded-2xl border border-line shrink-0 flex items-center justify-center text-2xl font-bold text-white shadow-inner"
                 style="background: linear-gradient(135deg, {{ $brand->primaryColor() }}, {{ $brand->accentColor() }});">
                {{ strtoupper(substr($brand->company_name ?? 'L', 0, 1)) }}
            </div>
        @endif
        <div class="flex-1 grid md:grid-cols-2 gap-6 min-w-0">
            <div>
                <h2 class="text-xl font-bold">{{ $brand->company_name ?? parse_url($brand->website_url, PHP_URL_HOST) }}</h2>
                @if($brand->industry)
                    <p class="text-sm text-muted">{{ $brand->industry }}</p>
                @endif
                @if($brand->description)
                    <p class="text-sm mt-2 leading-relaxed">{{ $brand->description }}</p>
                @endif
            </div>
            <div class="space-y-2.5 text-sm">
                <div class="flex items-center gap-2">
                    <span class="text-muted w-28 shrink-0">Primary color</span>
                    <span class="inline-block w-5 h-5 rounded border border-line shrink-0" style="background: {{ $brand->primaryColor() }}"></span>
                    <code class="text-xs">{{ $brand->primaryColor() }}</code>
                </div>
                <div class="flex items-center gap-2">
                    <span class="text-muted w-28 shrink-0">Accent</span>
                    <span class="inline-block w-5 h-5 rounded border border-line shrink-0" style="background: {{ $brand->accentColor() }}"></span>
                    <code class="text-xs">{{ $brand->accentColor() }}</code>
                </div>
                @if($tone)
                    <div class="flex gap-2"><span class="text-muted w-28 shrink-0">Tone</span><span class="min-w-0">{{ $tone }}</span></div>
                @endif
                @if(!empty($ctas))
                    <div class="flex gap-2">
                        <span class="text-muted w-28 shrink-0">CTAs</span>
                        <span class="flex flex-wrap gap-1.5 min-w-0">
                            @foreach($ctas as $cta)
                                <span class="inline-block px-2 py-0.5 rounded-md bg-bgmain border border-line text-xs">{{ $cta }}</span>
                            @endforeach
                        </span>
                    </div>
                @endif
            </div>
        </div>
    </div>
    @endif

    {{-- Asymmetric grid: wide banners span more columns, skyscrapers span two rows, squares fit naturally.
         items-start prevents short banners from stretching to fill taller rows. --}}
    <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-4 lg:gap-5 items-start" style="grid-auto-flow: dense;">
        @foreach($variants as $variant)
            @php
                $aspect = $variant->size_height > 0 ? $variant->size_width / $variant->size_height : 1;
                $span = match(true) {
                    $aspect >= 5   => 'md:col-span-2 lg:col-span-3',  // 728×90, 468×60, 320×50
                    $aspect >= 3   => 'md:col-span-2 lg:col-span-2',  // 970×250, 320×100
                    $aspect <= 0.5 => 'md:row-span-2 lg:row-span-2',  // 300×600, 160×600
                    default        => '',                             // 300×250, 336×280, 250×250
                };
            @endphp
            <div class="group bg-surface border border-line rounded-2xl overflow-hidden shadow-sm hover:shadow-md hover:-translate-y-0.5 transition-all {{ $span }}"
                 data-variant-id="{{ $variant->id }}" data-ad-w="{{ $variant->size_width }}" data-ad-h="{{ $variant->size_height }}">
                <div class="relative bg-bgmain" style="aspect-ratio: {{ $variant->size_width }}/{{ $variant->size_height }};">
                    @if($variant->html)
                        <iframe data-ad-frame
                                srcdoc="{{ $variant->html }}"
                                width="{{ $variant->size_width }}"
                                height="{{ $variant->size_height }}"
                                loading="lazy"
                                sandbox=""
                                scrolling="no"
                                class="absolute top-0 left-0 border-0 pointer-events-none origin-top-left"
                                style="width: {{ $variant->size_width }}px; height: {{ $variant->size_height }}px;"></iframe>
                    @else
                        <iframe data-ad-frame
                                srcdoc=""
                                width="{{ $variant->size_width }}"
                                height="{{ $variant->size_height }}"
                                loading="lazy"
                                sandbox=""
                                scrolling="no"
                                class="absolute top-0 left-0 border-0 pointer-events-none origin-top-left opacity-0"
                                style="width: {{ $variant->size_width }}px; height: {{ $variant->size_height }}px;"></iframe>
                    @endif
                    <div data-render-placeholder
                         class="absolute inset-0 transition-opacity duration-500 overflow-hidden"
                         style="opacity: {{ $variant->html ? 0 : 1 }};
                                background: linear-gradient(135deg, {{ $brand?->primaryColor() ?? '#2563EB' }}, {{ $brand?->accentColor() ?? '#7C3AED' }});">
                        {{-- Subtle shimmer sweep --}}
                        <div class="absolute inset-y-0 -left-1/2 w-1/2 bg-gradient-to-r from-transparent via-white/15 to-transparent animate-[shimmer_2s_ease-in-out_infinite]"></div>
                        <div class="absolute inset-0 p-3 flex flex-col justify-end text-white">
                            <p class="font-bold text-sm leading-tight">{{ $variant->headline }}</p>
                            <p class="text-[10px] opacity-80">{{ $variant->cta }}</p>
                        </div>
                        <div class="absolute top-2 right-2 w-4 h-4 rounded-full border-2 border-white/40 border-t-white animate-spin"></div>
                    </div>
                </div>
                <div class="p-3 text-xs flex items-center justify-between">
                    <span class="text-muted" style="font-variant-numeric: tabular-nums;">{{ $variant->size_width }}×{{ $variant->size_height }}</span>
                    <span class="px-1.5 py-0.5 rounded {{ $variant->source_type === 'event' ? 'bg-accent/10 text-accent' : 'bg-primary/10 text-primary' }}">
                        {{ $variant->source_type === 'event' ? 'Daily' : 'Brand' }}
                    </span>
                </div>
            </div>
        @endforeach
    </div>

    <div class="mt-14 mb-4 relative overflow-hidden rounded-3xl bg-gradient-to-br from-ink via-slate-800 to-ink text-white p-10 md:p-12 text-center">
        <div class="absolute -top-20 -right-20 w-72 h-72 rounded-full bg-primary/20 blur-3xl"></div>
        <div class="absolute -bottom-24 -left-12 w-72 h-72 rounded-full bg-accent/15 blur-3xl"></div>
        <div class="relative">
            <p class="text-xs font-semibold tracking-wider uppercase text-white/60 mb-3">Free for new accounts</p>
            <h2 class="text-3xl md:text-4xl font-extrabold tracking-tight mb-3">Take these 30 ads live with $500 in credit.</h2>
            <p class="text-white/70 max-w-xl mx-auto mb-7">Create your account to save this campaign, run it on partner display inventory, and unlock the full 1,000-ad test.</p>
            <a href="{{ route('create.claim', $session->uuid) }}" class="inline-flex items-center gap-2 bg-white text-ink font-bold px-8 py-4 rounded-xl shadow-2xl shadow-black/20 hover:scale-[1.02] transition">
                Claim $500 credit
                <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
            </a>
            <p class="text-xs text-white/50 mt-4">No credit card required.</p>
        </div>
    </div>
</section>

<style>
    @keyframes shimmer {
        0%   { transform: translateX(0); }
        100% { transform: translateX(400%); }
    }
</style>

<script>
// Each iframe is rendered at the ad's true pixel size; we use CSS
// transform:scale() to shrink it into whatever tile width the grid gives.
// ResizeObserver keeps the scale in sync as the layout reflows.
function scaleAdFrame(tile) {
    const frame = tile.querySelector('[data-ad-frame]');
    if (!frame) return;
    const adW = parseInt(tile.dataset.adW || '0', 10);
    if (!adW) return;
    const box = frame.parentElement;
    const tileW = box ? box.clientWidth : tile.clientWidth;
    if (!tileW) return;
    // Scale to fill the tile width — even if it means scaling up. Slight
    // typography blur at 1.25-2x is preferable to empty space around the ad.
    frame.style.transformOrigin = '0 0';
    frame.style.transform = `scale(${tileW / adW})`;
}
function scaleAllAdFrames() {
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
scaleAllAdFrames();
requestAnimationFrame(scaleAllAdFrames);
window.addEventListener('load', () => { scaleAllAdFrames(); watchAdFrames(); });
window.addEventListener('resize', scaleAllAdFrames);
watchAdFrames();

function previewLive(rendersUrl, total) {
    return {
        status: 'preview_streaming',
        total,
        done: 0,
        interval: null,
        start() {
            this.done = Array.from(document.querySelectorAll('[data-ad-frame]'))
                .filter(f => f.getAttribute('srcdoc')).length;
            requestAnimationFrame(scaleAllAdFrames);
            this.poll();
            this.interval = setInterval(() => this.poll(), 1500);
        },
        async poll() {
            try {
                const res = await fetch(rendersUrl, { headers: { Accept: 'application/json' } });
                const data = await res.json();
                this.status = data.status;
                let touched = false;
                for (const row of data.renders || []) {
                    const tile = document.querySelector(`[data-variant-id="${row.variant_id}"]`);
                    if (!tile) continue;
                    const frame = tile.querySelector('[data-ad-frame]');
                    const placeholder = tile.querySelector('[data-render-placeholder]');
                    if (frame && !frame.getAttribute('srcdoc')) {
                        frame.setAttribute('srcdoc', row.html);
                        frame.classList.remove('opacity-0');
                        if (placeholder) placeholder.style.opacity = '0';
                        scaleAdFrame(tile);
                        touched = true;
                    }
                }
                if (touched) scaleAllAdFrames();
                this.done = (data.renders || []).length;
                if (data.status === 'preview_ready') {
                    clearInterval(this.interval);
                }
            } catch (e) { /* noop */ }
        },
    };
}
</script>
@endsection
