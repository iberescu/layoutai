@extends('layouts.app')
@php $heading = 'Integrations'; @endphp

@section('content')
<div class="grid md:grid-cols-2 gap-6">
    <section class="bg-surface border border-line rounded-2xl p-6">
        <h2 class="font-semibold mb-2">Tracking pixel</h2>
        @if($pixelSite)
            <p class="text-sm text-muted mb-3">Install this snippet in the &lt;head&gt; of <strong>{{ $pixelSite->domain }}</strong>.</p>
            <pre class="bg-bgmain border border-line rounded-xl p-3 text-xs overflow-auto">&lt;script async src="{{ url('/pixel.js') }}" data-layout-site="{{ $pixelSite->site_id }}"&gt;&lt;/script&gt;</pre>
            <div class="mt-3 text-sm">
                Status:
                <span class="px-2 py-0.5 rounded text-xs
                    {{ $pixelSite->status === 'receiving_events' ? 'bg-success/10 text-success' : ($pixelSite->status === 'detected' ? 'bg-warning/10 text-warning' : 'bg-bgmain text-muted') }}">
                    {{ ucfirst(str_replace('_', ' ', $pixelSite->status)) }}
                </span>
            </div>
            <form method="POST" action="{{ route('integrations.verifyPixel') }}" class="mt-3">@csrf
                <input type="hidden" name="pixel_site_id" value="{{ $pixelSite->id }}">
                <button class="bg-primary text-white px-4 py-2 rounded-xl text-sm">Verify install</button>
            </form>
        @else
            <form method="POST" action="{{ route('integrations.registerPixel') }}" class="space-y-3">@csrf
                <div>
                    <label class="block text-sm mb-1">Domain</label>
                    <input name="domain" placeholder="example.com" class="w-full rounded-xl border-line">
                </div>
                <button class="bg-primary text-white px-4 py-2 rounded-xl text-sm">Generate pixel</button>
            </form>
        @endif
    </section>

    <section class="bg-surface border border-line rounded-2xl p-6">
        <h2 class="font-semibold mb-2">Product feed</h2>
        @if($productFeed)
            <p class="text-sm text-muted mb-3">Source: {{ ucfirst($productFeed->source) }} – status {{ $productFeed->status }}.</p>
            <p class="text-xs text-muted">{{ $productFeed->items()->count() }} products synced.</p>
        @else
            <form method="POST" action="{{ route('integrations.connectFeed') }}" enctype="multipart/form-data" class="space-y-3">@csrf
                <div>
                    <label class="block text-sm mb-1">Source</label>
                    <select name="source" class="w-full rounded-xl border-line">
                        <option value="csv">CSV upload</option>
                        <option value="xml">XML feed URL</option>
                        <option value="google_merchant">Google Merchant Center XML</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm mb-1">URL (XML/Merchant)</label>
                    <input name="url" placeholder="https://shop.example.com/feed.xml" class="w-full rounded-xl border-line">
                </div>
                <div>
                    <label class="block text-sm mb-1">Or upload CSV</label>
                    <input type="file" name="file" accept=".csv" class="text-sm">
                </div>
                <button class="bg-primary text-white px-4 py-2 rounded-xl text-sm">Connect feed</button>
            </form>
        @endif
    </section>
</div>
@endsection
