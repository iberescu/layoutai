@extends('layouts.app')
@php $heading = 'Reporting'; @endphp

@section('content')
<div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
    @foreach($metrics as $label => $value)
        <div class="bg-surface border border-line rounded-2xl p-4">
            <p class="text-xs text-muted uppercase tracking-wide">{{ $label }}</p>
            <p class="text-2xl font-bold mt-1">{{ $value }}</p>
        </div>
    @endforeach
</div>

<div class="bg-surface border border-line rounded-2xl p-6">
    <h2 class="font-semibold mb-4">Top ads by creative score</h2>
    <table class="w-full text-sm">
        <thead class="text-left text-muted">
            <tr><th class="py-2">Variant</th><th>Size</th><th>CTR</th><th>CR</th><th>Score</th></tr>
        </thead>
        <tbody>
            @foreach($topAds as $row)
                <tr class="border-t border-line">
                    <td class="py-2">{{ $row->headline ?: '—' }}</td>
                    <td>{{ $row->size_width }}×{{ $row->size_height }}</td>
                    <td>{{ number_format($row->ctr ?? 0, 2) }}%</td>
                    <td>{{ number_format($row->conversion_rate ?? 0, 2) }}%</td>
                    <td><strong>{{ number_format($row->creative_score ?? 0, 2) }}</strong></td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
@endsection
