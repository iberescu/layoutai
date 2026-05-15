@extends('layouts.app')
@php $heading = $campaign->name; @endphp

@section('content')
<div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
    @foreach(['draft' => 'Draft', 'ready' => 'Ready', 'running' => 'Running', 'paused' => 'Paused'] as $key => $label)
        <div class="bg-surface border border-line rounded-xl p-3 text-center">
            <p class="text-xs text-muted">{{ $label }}</p>
            <p class="text-xl font-bold">{{ $counts[$key] ?? 0 }}</p>
        </div>
    @endforeach
</div>

<form method="GET" class="flex items-center gap-2 mb-4">
    <select name="status" class="rounded-xl border-line text-sm">
        <option value="">All statuses</option>
        @foreach(['generated','needs_review','approved','rejected','scheduled','running','winner','archived'] as $s)
            <option value="{{ $s }}" @selected(request('status') === $s)>{{ ucwords(str_replace('_',' ', $s)) }}</option>
        @endforeach
    </select>
    <select name="size" class="rounded-xl border-line text-sm">
        <option value="">All sizes</option>
        @foreach(['300x250','336x280','728x90','970x250','160x600','300x600','320x50','320x100','468x60','250x250'] as $s)
            <option value="{{ $s }}" @selected(request('size') === $s)>{{ $s }}</option>
        @endforeach
    </select>
    <button class="rounded-xl bg-primary text-white px-4 py-2 text-sm">Filter</button>
</form>

<div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
    @foreach($variants as $variant)
        <div class="bg-surface border border-line rounded-2xl overflow-hidden">
            <div class="relative" style="aspect-ratio: {{ $variant->size_width }}/{{ $variant->size_height }};">
                @if($variant->renders->first()?->asset_url)
                    <img src="{{ $variant->renders->first()->asset_url }}" class="w-full h-full object-cover">
                @else
                    <div class="absolute inset-0" style="background: linear-gradient(135deg, {{ $brand?->primaryColor() ?? '#2563EB' }}, {{ $brand?->accentColor() ?? '#7C3AED' }});"></div>
                    <div class="absolute inset-0 p-3 text-white flex flex-col justify-end">
                        <p class="font-bold text-sm">{{ $variant->headline }}</p>
                        <p class="text-xs opacity-80">{{ $variant->cta }}</p>
                    </div>
                @endif
            </div>
            <div class="p-3 text-xs flex items-center justify-between">
                <span class="text-muted">{{ $variant->size_width }}×{{ $variant->size_height }}</span>
                <span class="px-1.5 py-0.5 rounded bg-bgmain text-muted">{{ str_replace('_',' ',$variant->status) }}</span>
            </div>
        </div>
    @endforeach
</div>

<div class="mt-6">{{ $variants->withQueryString()->links() }}</div>
@endsection
