@extends('layouts.app')
@php $heading = 'Support inbox'; @endphp

@section('content')
@php
    $open    = $counts['open']    ?? 0;
    $read    = $counts['read']    ?? 0;
    $replied = $counts['replied'] ?? 0;
    $total   = $open + $read + $replied;
@endphp

<div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-5">
    <div class="bg-surface border border-line rounded-xl p-4">
        <p class="text-xs text-muted uppercase tracking-wide font-semibold">Total</p>
        <p class="text-2xl font-bold mt-0.5" style="font-variant-numeric: tabular-nums;">{{ $total }}</p>
    </div>
    <div class="bg-surface border border-line rounded-xl p-4">
        <p class="text-xs text-muted uppercase tracking-wide font-semibold">Open</p>
        <p class="text-2xl font-bold mt-0.5 text-warning" style="font-variant-numeric: tabular-nums;">{{ $open }}</p>
    </div>
    <div class="bg-surface border border-line rounded-xl p-4">
        <p class="text-xs text-muted uppercase tracking-wide font-semibold">Read</p>
        <p class="text-2xl font-bold mt-0.5" style="font-variant-numeric: tabular-nums;">{{ $read }}</p>
    </div>
    <div class="bg-surface border border-line rounded-xl p-4">
        <p class="text-xs text-muted uppercase tracking-wide font-semibold">Replied</p>
        <p class="text-2xl font-bold mt-0.5 text-success" style="font-variant-numeric: tabular-nums;">{{ $replied }}</p>
    </div>
</div>

<form method="GET" class="flex flex-wrap items-center gap-2 mb-4">
    <input type="search" name="q" value="{{ request('q') }}" placeholder="Search email or body…"
           class="rounded-xl border-line text-sm w-64">
    <select name="status" class="rounded-xl border-line text-sm">
        <option value="">All statuses</option>
        @foreach(['open','read','replied'] as $s)
            <option value="{{ $s }}" @selected(request('status') === $s)>{{ ucfirst($s) }}</option>
        @endforeach
    </select>
    <button class="rounded-xl bg-primary text-white px-4 py-2 text-sm">Filter</button>
    @if(request('q') || request('status'))
        <a href="{{ route('admin.support.index') }}" class="text-sm text-muted hover:text-ink">Clear</a>
    @endif
</form>

<div class="bg-surface border border-line rounded-2xl overflow-hidden">
    @if($messages->isEmpty())
        <div class="p-12 text-center text-muted">
            <p class="text-sm">No support messages match this filter.</p>
        </div>
    @else
        <table class="w-full text-sm">
            <thead class="bg-bgmain text-muted text-xs uppercase tracking-wide">
                <tr>
                    <th class="text-left font-semibold px-4 py-3">From</th>
                    <th class="text-left font-semibold px-4 py-3">Message</th>
                    <th class="text-left font-semibold px-4 py-3 hidden md:table-cell">Page</th>
                    <th class="text-left font-semibold px-4 py-3">Status</th>
                    <th class="text-left font-semibold px-4 py-3">When</th>
                    <th></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @foreach($messages as $m)
                    @php
                        $statusColor = match($m->status) {
                            'open'    => 'bg-warning/15 text-warning',
                            'read'    => 'bg-bgmain text-muted',
                            'replied' => 'bg-success/15 text-success',
                            default   => 'bg-bgmain text-muted',
                        };
                    @endphp
                    <tr class="hover:bg-bgmain/50 transition">
                        <td class="px-4 py-3 align-top">
                            <p class="font-semibold text-ink">{{ $m->email }}</p>
                            @if($m->user)
                                <p class="text-xs text-muted">user #{{ $m->user->id }} — {{ $m->user->name }}</p>
                            @endif
                        </td>
                        <td class="px-4 py-3 align-top max-w-md">
                            <p class="line-clamp-2 leading-snug">{{ $m->body }}</p>
                        </td>
                        <td class="px-4 py-3 align-top hidden md:table-cell">
                            @if($m->page_url)
                                <a href="{{ $m->page_url }}" target="_blank" rel="noopener" class="text-xs text-primary hover:underline" title="{{ $m->page_url }}">
                                    {{ \Illuminate\Support\Str::limit(parse_url($m->page_url, PHP_URL_PATH) ?: $m->page_url, 40) }}
                                </a>
                            @else
                                <span class="text-xs text-muted">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 align-top">
                            <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-md text-[10px] font-semibold uppercase tracking-wide {{ $statusColor }}">
                                {{ $m->status }}
                            </span>
                        </td>
                        <td class="px-4 py-3 align-top text-xs text-muted whitespace-nowrap">
                            {{ $m->created_at->diffForHumans() }}
                        </td>
                        <td class="px-4 py-3 align-top">
                            <a href="{{ route('admin.support.show', $m) }}" class="text-sm font-semibold text-primary hover:underline">Open →</a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>

<div class="mt-5">{{ $messages->withQueryString()->links() }}</div>
@endsection
