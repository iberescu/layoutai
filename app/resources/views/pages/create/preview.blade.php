@extends('layouts.marketing')

@section('content')
<section class="max-w-7xl mx-auto px-6 py-12"
    x-data="previewLive('{{ route('create.renders', $session->uuid) }}', {{ $variants->count() }})"
    x-init="start()">
    <div class="flex flex-col md:flex-row md:items-end justify-between gap-4 mb-10">
        <div>
            <span class="inline-block px-3 py-1 rounded-full text-xs font-semibold mb-3"
                  :class="status === 'preview_ready' ? 'bg-success/10 text-success' : 'bg-primary/10 text-primary animate-pulse'">
                <span x-text="status === 'preview_ready' ? 'Preview ready' : `Generating · ${done}/${total}`"></span>
            </span>
            <h1 class="text-4xl md:text-5xl font-extrabold tracking-tight">Your first ads are ready.</h1>
            <p class="text-muted mt-2 max-w-2xl">We crawled your site, learned the brand, and generated 30 ads: 20 brand/product and 10 daily-event variants. Tiles fill in live as each render finishes.</p>
        </div>
        <a href="{{ route('create.claim', $session->uuid) }}" class="bg-primary text-white font-semibold px-6 py-3.5 rounded-xl shadow-lg shadow-primary/20">Claim $500 credit</a>
    </div>

    @if($brand)
    <div class="bg-surface border border-line rounded-3xl p-6 md:p-8 mb-10 flex flex-col md:flex-row gap-6 items-start">
        @if($brand->logoAsset)
            <img src="{{ $brand->logoAsset->url() }}" alt="" class="w-20 h-20 rounded-2xl border border-line bg-bgmain object-contain p-2">
        @else
            <div class="w-20 h-20 rounded-2xl border border-line bg-bgmain flex items-center justify-center text-2xl font-bold text-muted">{{ substr($brand->company_name ?? 'L', 0, 1) }}</div>
        @endif
        <div class="flex-1 grid md:grid-cols-2 gap-6">
            <div>
                <h2 class="text-xl font-bold">{{ $brand->company_name ?? parse_url($brand->website_url, PHP_URL_HOST) }}</h2>
                <p class="text-sm text-muted">{{ $brand->industry ?? 'Brand' }}</p>
                <p class="text-sm mt-2">{{ $brand->description }}</p>
            </div>
            <div class="space-y-2 text-sm">
                <div class="flex items-center gap-2">
                    <span class="text-muted w-28">Primary color</span>
                    <span class="inline-block w-5 h-5 rounded border border-line" style="background: {{ $brand->primaryColor() }}"></span>
                    <code class="text-xs">{{ $brand->primaryColor() }}</code>
                </div>
                <div class="flex items-center gap-2">
                    <span class="text-muted w-28">Accent</span>
                    <span class="inline-block w-5 h-5 rounded border border-line" style="background: {{ $brand->accentColor() }}"></span>
                    <code class="text-xs">{{ $brand->accentColor() }}</code>
                </div>
                <div><span class="text-muted">Tone:</span> {{ $brand->brand_voice_json['tone'] ?? '—' }}</div>
                <div><span class="text-muted">Recommended CTAs:</span> {{ implode(', ', $brand->ctas_json ?? []) }}</div>
            </div>
        </div>
    </div>
    @endif

    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-5">
        @foreach($variants as $variant)
            @php $initial = $variant->renders->first()?->asset_url; @endphp
            <div class="bg-surface border border-line rounded-2xl overflow-hidden shadow-sm hover:shadow-md transition" data-variant-id="{{ $variant->id }}">
                <div class="relative" style="aspect-ratio: {{ $variant->size_width }}/{{ $variant->size_height }};">
                    <img data-render-img alt=""
                         class="absolute inset-0 w-full h-full object-cover transition-opacity"
                         src="{{ $initial ?: '' }}"
                         style="opacity: {{ $initial ? 1 : 0 }};">
                    <div data-render-placeholder
                         class="absolute inset-0 transition-opacity"
                         style="opacity: {{ $initial ? 0 : 1 }};
                                background: linear-gradient(135deg, {{ $brand?->primaryColor() ?? '#2563EB' }}, {{ $brand?->accentColor() ?? '#7C3AED' }});">
                        <div class="absolute inset-0 p-3 flex flex-col justify-end text-white">
                            <p class="font-bold text-sm leading-tight">{{ $variant->headline }}</p>
                            <p class="text-[10px] opacity-80">{{ $variant->cta }}</p>
                        </div>
                        <div class="absolute top-2 right-2 w-4 h-4 rounded-full border-2 border-white/40 border-t-white animate-spin"></div>
                    </div>
                </div>
                <div class="p-3 text-xs flex items-center justify-between">
                    <span class="text-muted">{{ $variant->size_width }}×{{ $variant->size_height }}</span>
                    <span class="px-1.5 py-0.5 rounded {{ $variant->source_type === 'event' ? 'bg-accent/10 text-accent' : 'bg-primary/10 text-primary' }}">
                        {{ $variant->source_type === 'event' ? 'Daily' : 'Brand' }}
                    </span>
                </div>
            </div>
        @endforeach
    </div>

    <div class="mt-12 text-center">
        <a href="{{ route('create.claim', $session->uuid) }}" class="inline-block bg-primary text-white font-semibold px-8 py-4 rounded-xl shadow-lg shadow-primary/20">
            Create free account and claim $500 ad credit
        </a>
    </div>
</section>

<script>
function previewLive(rendersUrl, total) {
    return {
        status: 'preview_streaming',
        total,
        done: 0,
        interval: null,
        start() {
            // Count tiles that arrived already-rendered from the initial SSR.
            this.done = document.querySelectorAll('[data-render-img]')
                ? Array.from(document.querySelectorAll('[data-render-img]'))
                    .filter(img => img.getAttribute('src')).length
                : 0;
            this.poll();
            this.interval = setInterval(() => this.poll(), 1500);
        },
        async poll() {
            try {
                const res = await fetch(rendersUrl, { headers: { Accept: 'application/json' } });
                const data = await res.json();
                this.status = data.status;
                for (const row of data.renders || []) {
                    const tile = document.querySelector(`[data-variant-id="${row.variant_id}"]`);
                    if (!tile) continue;
                    const img = tile.querySelector('[data-render-img]');
                    const placeholder = tile.querySelector('[data-render-placeholder]');
                    if (img && !img.getAttribute('src')) {
                        img.src = row.asset_url;
                        img.style.opacity = '1';
                        if (placeholder) placeholder.style.opacity = '0';
                    }
                }
                this.done = (data.renders || []).length;
                if (data.status === 'preview_ready') {
                    clearInterval(this.interval);
                }
            } catch (e) { /* noop, retry on next tick */ }
        },
    };
}
</script>
@endsection
