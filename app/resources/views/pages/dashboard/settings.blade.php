@extends('layouts.app')
@php $heading = 'Settings'; @endphp

@section('content')
<div class="bg-surface border border-line rounded-2xl p-6 max-w-2xl">
    <h2 class="font-semibold mb-4">Workspace</h2>
    <dl class="text-sm space-y-2">
        <div class="flex"><dt class="w-40 text-muted">Name</dt><dd>{{ $workspace->name }}</dd></div>
        <div class="flex"><dt class="w-40 text-muted">Slug</dt><dd>{{ $workspace->slug }}</dd></div>
        <div class="flex"><dt class="w-40 text-muted">Members</dt><dd>{{ $workspace->members()->count() }}</dd></div>
        <div class="flex"><dt class="w-40 text-muted">Credit balance</dt><dd>${{ number_format($workspace->creditBalanceCents() / 100, 2) }}</dd></div>
    </dl>
</div>
@if($brand)
<div class="bg-surface border border-line rounded-2xl p-6 max-w-2xl mt-6">
    <h2 class="font-semibold mb-4">Brand</h2>
    <dl class="text-sm space-y-2">
        <div class="flex"><dt class="w-40 text-muted">Company</dt><dd>{{ $brand->company_name }}</dd></div>
        <div class="flex"><dt class="w-40 text-muted">Industry</dt><dd>{{ $brand->industry }}</dd></div>
        <div class="flex"><dt class="w-40 text-muted">Tone</dt><dd>{{ $brand->brand_voice_json['tone'] ?? '—' }}</dd></div>
        <div class="flex"><dt class="w-40 text-muted">Forbidden words</dt><dd>{{ implode(', ', $brand->brand_voice_json['words_to_avoid'] ?? []) }}</dd></div>
    </dl>
</div>
@endif
@endsection
