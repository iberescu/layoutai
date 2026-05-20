@extends('layouts.app')
@php $heading = 'Overview'; @endphp

@section('content')
@php
    $firstName = trim(strtok(auth()->user()->name ?? '', ' '));
    $creditBalance = ($creditBalanceCents ?? 0) / 100;
    $pixelLabel = ucfirst(str_replace('_', ' ', $pixelStatus ?? 'not_installed'));
    $pixelLive = ($pixelStatus ?? '') === 'receiving_events';
@endphp

<div class="mb-8">
    <p class="text-sm text-muted">Welcome back{{ $firstName ? ', ' . $firstName : '' }}.</p>
    <h2 class="text-2xl font-bold tracking-tight mt-0.5">Your workspace at a glance</h2>
</div>

@if(($needsReviewCount ?? 0) > 0)
<div class="mb-6 flex items-center gap-3 rounded-2xl border border-warning/30 bg-warning/10 px-5 py-3.5">
    <span class="inline-flex w-8 h-8 rounded-full bg-warning text-white items-center justify-center text-sm font-bold shrink-0">{{ $needsReviewCount }}</span>
    <div class="flex-1 min-w-0">
        <p class="font-semibold text-sm">{{ $needsReviewCount }} ad{{ $needsReviewCount === 1 ? '' : 's' }} awaiting your review</p>
        <p class="text-xs text-muted">Approve or reject before they go live on partner inventory.</p>
    </div>
    @if($campaign)
    <a href="{{ route('campaigns.show', ['campaign' => $campaign->id, 'status' => 'needs_review']) }}" class="text-sm font-semibold text-warning hover:underline whitespace-nowrap">Review →</a>
    @endif
</div>
@endif

<div class="grid md:grid-cols-3 gap-4 mb-8">
    {{-- Credit balance --}}
    <div class="relative bg-surface border border-line rounded-2xl p-5 overflow-hidden">
        <div class="absolute -top-8 -right-8 w-24 h-24 rounded-full bg-gradient-to-br from-success/20 to-success/0 blur-xl"></div>
        <div class="relative">
            <div class="flex items-center justify-between mb-3">
                <p class="text-sm text-muted">Credit balance</p>
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="text-success">
                    <path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>
                </svg>
            </div>
            <p class="text-3xl font-bold tracking-tight" style="font-variant-numeric: tabular-nums;">
                <span class="text-muted text-xl align-top mr-0.5">$</span>{{ number_format($creditBalance, 2) }}
            </p>
            <p class="text-xs text-muted mt-1.5">Promotional credit · expires in 90 days</p>
        </div>
    </div>

    {{-- Generated ads --}}
    <div class="relative bg-surface border border-line rounded-2xl p-5 overflow-hidden">
        <div class="absolute -top-8 -right-8 w-24 h-24 rounded-full bg-gradient-to-br from-primary/20 to-primary/0 blur-xl"></div>
        <div class="relative">
            <div class="flex items-center justify-between mb-3">
                <p class="text-sm text-muted">Generated ads</p>
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="text-primary">
                    <rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/>
                    <rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>
                </svg>
            </div>
            <p class="text-3xl font-bold tracking-tight" style="font-variant-numeric: tabular-nums;">{{ $generatedCount ?? 0 }}</p>
            <p class="text-xs text-muted mt-1.5">
                @if(($needsReviewCount ?? 0) > 0)
                    <span class="font-medium text-warning">{{ $needsReviewCount }} awaiting review</span>
                @else
                    Across 10 IAB display sizes
                @endif
            </p>
        </div>
    </div>

    {{-- Pixel status --}}
    <div class="relative bg-surface border border-line rounded-2xl p-5 overflow-hidden">
        <div class="absolute -top-8 -right-8 w-24 h-24 rounded-full bg-gradient-to-br {{ $pixelLive ? 'from-success/20' : 'from-accent/20' }} to-transparent blur-xl"></div>
        <div class="relative">
            <div class="flex items-center justify-between mb-3">
                <p class="text-sm text-muted">Tracking pixel</p>
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="{{ $pixelLive ? 'text-success' : 'text-muted' }}">
                    <circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="4"/><circle cx="12" cy="12" r="1.2" fill="currentColor"/>
                </svg>
            </div>
            <div class="flex items-center gap-2 mt-0.5">
                <span class="w-2 h-2 rounded-full shrink-0 {{ $pixelLive ? 'bg-success animate-pulse' : 'bg-muted/40' }}"></span>
                <p class="text-lg font-semibold leading-tight">{{ $pixelLabel }}</p>
            </div>
            <a href="{{ route('integrations.index') }}" class="inline-flex items-center gap-1 text-xs text-primary mt-2 font-medium hover:gap-1.5 transition-all">
                {{ $pixelLive ? 'View integration' : 'Install pixel' }} →
            </a>
        </div>
    </div>
</div>

<div class="bg-surface border border-line rounded-2xl overflow-hidden">
    <div class="p-5 border-b border-line flex items-center justify-between gap-4">
        <div class="min-w-0">
            <div class="flex items-center gap-2 mb-0.5">
                @if($campaign?->brandProfile)
                    <span class="inline-flex w-5 h-5 rounded shrink-0 border border-line" style="background: linear-gradient(135deg, {{ $campaign->brandProfile->primaryColor() }}, {{ $campaign->brandProfile->accentColor() }});"></span>
                @endif
                <h2 class="font-semibold truncate">{{ $campaign?->name ?? 'First AI Display Campaign' }}</h2>
            </div>
            <p class="text-xs text-muted">{{ $campaign?->brandProfile?->company_name ?? '' }}{{ $campaign?->brandProfile?->company_name && $campaign?->goal ? ' · ' : '' }}{{ $campaign?->goal ? ucfirst($campaign->goal) . ' goal' : '' }}</p>
        </div>
        @php
            $statusColors = [
                'draft'   => 'bg-warning/10 text-warning',
                'ready'   => 'bg-primary/10 text-primary',
                'running' => 'bg-success/10 text-success',
                'paused'  => 'bg-muted/10 text-muted',
            ];
            $st = $campaign?->status ?? 'draft';
        @endphp
        <span class="px-2.5 py-1 rounded-full text-xs font-semibold {{ $statusColors[$st] ?? 'bg-muted/10 text-muted' }} whitespace-nowrap">{{ ucfirst($st) }}</span>
    </div>

    @if($campaign && ($sampleVariants ?? collect())->isNotEmpty())
        <div class="p-5">
            <div class="grid grid-cols-3 gap-3 mb-4">
                @foreach($sampleVariants as $variant)
                    <a href="{{ route('campaigns.show', $campaign->id) }}" class="block relative rounded-xl border border-line bg-bgmain overflow-hidden hover:shadow-md transition group"
                       data-variant-id="dash-{{ $variant->id }}" data-ad-w="{{ $variant->size_width }}" data-ad-h="{{ $variant->size_height }}">
                        <div class="relative" style="aspect-ratio: {{ $variant->size_width }}/{{ $variant->size_height }};">
                            <iframe data-ad-frame
                                    srcdoc="{{ $variant->html }}"
                                    width="{{ $variant->size_width }}"
                                    height="{{ $variant->size_height }}"
                                    loading="lazy"
                                    sandbox=""
                                    scrolling="no"
                                    class="absolute top-0 left-0 border-0 pointer-events-none"
                                    style="width: {{ $variant->size_width }}px; height: {{ $variant->size_height }}px; transform-origin: 0 0;"></iframe>
                        </div>
                        <div class="absolute bottom-1.5 left-1.5 px-1.5 py-0.5 rounded bg-ink/80 text-white text-[10px] font-medium" style="font-variant-numeric: tabular-nums;">
                            {{ $variant->size_width }}×{{ $variant->size_height }}
                        </div>
                    </a>
                @endforeach
            </div>
            <div class="flex items-center justify-between pt-2 border-t border-line">
                <p class="text-xs text-muted">{{ $generatedCount }} ad{{ $generatedCount === 1 ? '' : 's' }} ready · review and approve before going live</p>
                <a href="{{ route('campaigns.show', $campaign->id) }}" class="inline-flex items-center gap-1 text-sm font-semibold text-primary hover:gap-1.5 transition-all">Open campaign →</a>
            </div>
        </div>
    @elseif($campaign)
        <div class="p-5">
            <p class="text-sm text-muted mb-3">Variants still building. Check back in a moment.</p>
            <a href="{{ route('campaigns.show', $campaign->id) }}" class="text-primary text-sm font-medium">Open campaign →</a>
        </div>
    @else
        <div class="p-8 text-center">
            <div class="w-14 h-14 mx-auto rounded-2xl bg-bgmain border border-line flex items-center justify-center mb-3">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="text-muted">
                    <path d="M12 4v16M4 12h16"/>
                </svg>
            </div>
            <p class="font-semibold mb-1">No campaign yet</p>
            <p class="text-sm text-muted mb-4">Start one from your homepage to generate your first 30 ads.</p>
            <a href="{{ route('create.index') }}" class="inline-flex items-center gap-2 bg-primary text-white text-sm font-semibold px-4 py-2 rounded-lg">New campaign →</a>
        </div>
    @endif
</div>

<script>
// Scale each thumbnail iframe to fit the 1/3-width tile.
function scaleDashFrames() {
    document.querySelectorAll('[data-variant-id^="dash-"]').forEach(tile => {
        const frame = tile.querySelector('[data-ad-frame]');
        if (!frame) return;
        const adW = parseInt(tile.dataset.adW || '0', 10);
        if (!adW) return;
        const box = frame.parentElement;
        const tileW = box ? box.clientWidth : tile.clientWidth;
        if (!tileW) return;
        frame.style.transform = `scale(${Math.min(1, tileW / adW)})`;
    });
}
scaleDashFrames();
requestAnimationFrame(scaleDashFrames);
window.addEventListener('load', scaleDashFrames);
window.addEventListener('resize', scaleDashFrames);
if (typeof ResizeObserver !== 'undefined') {
    const ro = new ResizeObserver(scaleDashFrames);
    document.querySelectorAll('[data-variant-id^="dash-"] > .relative').forEach(el => ro.observe(el));
}
</script>
@endsection
