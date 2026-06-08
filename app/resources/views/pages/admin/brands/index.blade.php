@extends('layouts.app')
@php $heading = 'Brands (CRM)'; @endphp

@section('content')
<div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-5">
    <div class="bg-surface border border-line rounded-xl p-4">
        <p class="text-xs text-muted uppercase tracking-wide font-semibold">Brands</p>
        <p class="text-2xl font-bold mt-0.5" style="font-variant-numeric: tabular-nums;">{{ number_format($stats['total']) }}</p>
    </div>
    <div class="bg-surface border border-line rounded-xl p-4">
        <p class="text-xs text-muted uppercase tracking-wide font-semibold">Claimed account</p>
        <p class="text-2xl font-bold mt-0.5 text-success" style="font-variant-numeric: tabular-nums;">{{ number_format($stats['claimed']) }}</p>
    </div>
    <div class="bg-surface border border-line rounded-xl p-4">
        <p class="text-xs text-muted uppercase tracking-wide font-semibold">Ecommerce</p>
        <p class="text-2xl font-bold mt-0.5" style="font-variant-numeric: tabular-nums;">{{ number_format($stats['shops']) }}</p>
    </div>
    <div class="bg-surface border border-line rounded-xl p-4">
        <p class="text-xs text-muted uppercase tracking-wide font-semibold">Ads generated</p>
        <p class="text-2xl font-bold mt-0.5" style="font-variant-numeric: tabular-nums;">{{ number_format($stats['ads']) }}</p>
    </div>
</div>

<form method="GET" class="flex flex-wrap items-center gap-2 mb-4">
    <input type="search" name="q" value="{{ request('q') }}" placeholder="Search company, website, industry…"
           class="rounded-xl border-line text-sm w-72">
    <select name="filter" class="rounded-xl border-line text-sm">
        @foreach(['' => 'All brands', 'claimed' => 'Has account', 'prospect' => 'Prospects (no account)', 'ecommerce' => 'Ecommerce', 'premium' => 'Premium'] as $val => $label)
            <option value="{{ $val }}" @selected(request('filter') === $val)>{{ $label }}</option>
        @endforeach
    </select>
    <button class="rounded-xl bg-primary text-white px-4 py-2 text-sm">Filter</button>
    @if(request('q') || request('filter'))
        <a href="{{ route('admin.brands.index') }}" class="text-sm text-muted hover:text-ink">Clear</a>
    @endif
</form>

<div class="bg-surface border border-line rounded-2xl overflow-hidden">
    @if($brands->isEmpty())
        <div class="p-12 text-center text-muted"><p class="text-sm">No brands match this filter.</p></div>
    @else
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-bgmain text-muted text-xs uppercase tracking-wide">
                <tr>
                    <th class="text-left font-semibold px-4 py-3">Brand</th>
                    <th class="text-left font-semibold px-4 py-3 hidden lg:table-cell">Summary</th>
                    <th class="text-right font-semibold px-4 py-3">Ads</th>
                    <th class="text-left font-semibold px-4 py-3">Credit</th>
                    <th class="text-left font-semibold px-4 py-3 hidden md:table-cell">Registered</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @foreach($brands as $b)
                    @php
                        $logo = $b->displayLogoUrl();
                        $ws   = $b->workspace;
                        $host = $b->website_url ? (parse_url($b->website_url, PHP_URL_HOST) ?: $b->website_url) : null;
                        $initial = strtoupper(mb_substr($b->company_name ?: ($host ?: '?'), 0, 1));
                    @endphp
                    <tr class="hover:bg-bgmain/50 transition align-top">
                        {{-- Brand: logo + name + website + ecommerce badge --}}
                        <td class="px-4 py-3">
                            <div class="flex items-start gap-3">
                                <div class="shrink-0 w-10 h-10 rounded-lg border border-line bg-white overflow-hidden flex items-center justify-center">
                                    @if($logo)
                                        <img src="{{ $logo }}" alt="" loading="lazy" class="w-full h-full object-contain">
                                    @else
                                        <span class="w-full h-full flex items-center justify-center text-white font-bold"
                                              style="background: linear-gradient(135deg, {{ $b->primaryColor() }}, {{ $b->accentColor() }});">{{ $initial }}</span>
                                    @endif
                                </div>
                                <div class="min-w-0">
                                    <p class="font-semibold text-ink truncate">{{ $b->company_name ?: ($host ?: 'Untitled') }}</p>
                                    @if($host)
                                        <a href="{{ $b->website_url }}" target="_blank" rel="noopener" class="text-xs text-primary hover:underline">{{ $host }}</a>
                                    @endif
                                    <div class="flex flex-wrap gap-1 mt-1">
                                        @if($b->industry)
                                            <span class="px-1.5 py-0.5 rounded-md text-[10px] font-semibold uppercase tracking-wide bg-bgmain text-muted">{{ \Illuminate\Support\Str::limit($b->industry, 24) }}</span>
                                        @endif
                                        @if($b->is_ecommerce)
                                            <span class="px-1.5 py-0.5 rounded-md text-[10px] font-semibold uppercase tracking-wide bg-success/15 text-success">Shop{{ $b->ecommerce_platform ? ' · '.$b->ecommerce_platform : '' }}</span>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </td>

                        {{-- Summary --}}
                        <td class="px-4 py-3 hidden lg:table-cell max-w-md">
                            <p class="text-muted leading-snug line-clamp-3">{{ $b->description ?: '—' }}</p>
                        </td>

                        {{-- Ads generated --}}
                        <td class="px-4 py-3 text-right">
                            <span class="font-semibold" style="font-variant-numeric: tabular-nums;">{{ number_format($b->ad_variants_count) }}</span>
                        </td>

                        {{-- Credit / account --}}
                        <td class="px-4 py-3 whitespace-nowrap">
                            @if($ws)
                                @php
                                    $bal       = $ws->creditBalanceCents();
                                    $remaining = $ws->freeCreditRemainingThisMonthCents(); // null = premium/uncapped
                                @endphp
                                <p class="font-semibold text-ink" style="font-variant-numeric: tabular-nums;">${{ number_format($bal / 100, 2) }}</p>
                                @if($ws->isPremium())
                                    <span class="px-1.5 py-0.5 rounded-md text-[10px] font-semibold uppercase tracking-wide bg-gradient-to-r from-primary to-accent text-white">Premium</span>
                                @else
                                    <span class="text-[11px] text-muted">${{ number_format(($remaining ?? 0) / 100, 0) }} free left this mo.</span>
                                @endif
                            @else
                                <span class="px-1.5 py-0.5 rounded-md text-[10px] font-semibold uppercase tracking-wide bg-warning/15 text-warning">Prospect</span>
                            @endif
                        </td>

                        {{-- Registered --}}
                        <td class="px-4 py-3 hidden md:table-cell text-xs text-muted whitespace-nowrap" title="{{ $b->created_at }}">
                            {{ $b->created_at->diffForHumans() }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        </div>
    @endif
</div>

<div class="mt-5">{{ $brands->withQueryString()->links() }}</div>
@endsection
