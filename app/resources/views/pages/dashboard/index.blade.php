@extends('layouts.app')
@php $heading = 'Overview'; @endphp

@section('content')
<div class="grid md:grid-cols-3 gap-4 mb-8">
    <div class="bg-surface border border-line rounded-2xl p-5">
        <p class="text-sm text-muted">Credit balance</p>
        <p class="text-3xl font-bold mt-1">${{ number_format(($creditBalanceCents ?? 0) / 100, 2) }}</p>
        <p class="text-xs text-muted mt-1">Promotional credit</p>
    </div>
    <div class="bg-surface border border-line rounded-2xl p-5">
        <p class="text-sm text-muted">Generated ads</p>
        <p class="text-3xl font-bold mt-1">{{ $generatedCount ?? 0 }}</p>
        <p class="text-xs text-muted mt-1">Awaiting review: {{ $needsReviewCount ?? 0 }}</p>
    </div>
    <div class="bg-surface border border-line rounded-2xl p-5">
        <p class="text-sm text-muted">Pixel</p>
        <p class="text-lg font-semibold mt-1">{{ ucfirst(str_replace('_', ' ', $pixelStatus ?? 'not_installed')) }}</p>
        <a href="{{ route('integrations.index') }}" class="text-xs text-primary">View integration →</a>
    </div>
</div>

<div class="bg-surface border border-line rounded-2xl">
    <div class="p-5 border-b border-line flex items-center justify-between">
        <h2 class="font-semibold">First AI Display Campaign</h2>
        <span class="px-2 py-0.5 rounded text-xs bg-warning/10 text-warning">{{ ucfirst($campaign?->status ?? 'draft') }}</span>
    </div>
    @if($campaign)
        <div class="p-5">
            <a href="{{ route('campaigns.show', $campaign->id) }}" class="text-primary text-sm font-medium">Open campaign →</a>
        </div>
    @else
        <div class="p-5 text-sm text-muted">No campaign yet.</div>
    @endif
</div>
@endsection
