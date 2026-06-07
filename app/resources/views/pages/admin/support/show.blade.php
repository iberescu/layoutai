@extends('layouts.app')
@php $heading = 'Support · message #' . $message->id; @endphp

@section('content')
@php
    $statusColor = match($message->status) {
        'open'    => 'bg-warning/15 text-warning',
        'read'    => 'bg-bgmain text-muted',
        'replied' => 'bg-success/15 text-success',
        default   => 'bg-bgmain text-muted',
    };
@endphp

<div class="mb-4 flex items-center justify-between gap-3">
    <a href="{{ route('admin.support.index') }}" class="text-sm text-muted hover:text-ink inline-flex items-center gap-1.5">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M19 12H5m7-7-7 7 7 7"/></svg>
        Back to inbox
    </a>
    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md text-[11px] font-semibold uppercase tracking-wide {{ $statusColor }}">
        {{ $message->status }}
    </span>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
    <div class="lg:col-span-2 bg-surface border border-line rounded-2xl p-6">
        <div class="flex items-start gap-3 mb-5 pb-4 border-b border-line">
            <div class="w-10 h-10 rounded-full bg-gradient-to-br from-primary to-accent text-white font-bold flex items-center justify-center shrink-0">
                {{ strtoupper(substr($message->email, 0, 1)) }}
            </div>
            <div class="min-w-0">
                <p class="font-semibold text-ink truncate">{{ $message->email }}</p>
                @if($message->user)
                    <p class="text-xs text-muted">user #{{ $message->user->id }} — {{ $message->user->name }}</p>
                @endif
                <p class="text-xs text-muted mt-0.5">{{ $message->created_at->format('Y-m-d H:i') }} · {{ $message->created_at->diffForHumans() }}</p>
            </div>
        </div>

        <pre class="whitespace-pre-wrap font-sans text-[15px] leading-relaxed text-ink">{{ $message->body }}</pre>

        <div class="mt-6 pt-4 border-t border-line flex flex-wrap gap-2">
            <a href="mailto:{{ $message->email }}?subject=Re:%20Layout.ai%20support%20%23{{ $message->id }}&body=%0A%0A---%0AOriginal%20message%3A%0A{{ rawurlencode($message->body) }}"
               class="inline-flex items-center gap-1.5 rounded-lg bg-primary hover:bg-primary/90 text-white px-3.5 py-2 text-sm font-semibold">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><path d="m22 6-10 7L2 6"/></svg>
                Reply via email
            </a>

            <form method="POST" action="{{ route('admin.support.update', $message) }}">
                @csrf @method('PATCH')
                <input type="hidden" name="status" value="replied">
                <button class="inline-flex items-center gap-1.5 rounded-lg bg-success/10 hover:bg-success/15 text-success px-3.5 py-2 text-sm font-semibold">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                    Mark as replied
                </button>
            </form>

            @if($message->status !== 'open')
                <form method="POST" action="{{ route('admin.support.update', $message) }}">
                    @csrf @method('PATCH')
                    <input type="hidden" name="status" value="open">
                    <button class="inline-flex items-center gap-1.5 rounded-lg bg-bgmain hover:bg-line/40 text-muted px-3.5 py-2 text-sm font-semibold">
                        Mark as open
                    </button>
                </form>
            @endif

            <form method="POST" action="{{ route('admin.support.destroy', $message) }}"
                  onsubmit="return confirm('Delete this message permanently?');"
                  class="ml-auto">
                @csrf @method('DELETE')
                <button class="inline-flex items-center gap-1.5 rounded-lg text-red-600 hover:bg-red-50 px-3.5 py-2 text-sm font-semibold">
                    Delete
                </button>
            </form>
        </div>
    </div>

    <aside class="bg-surface border border-line rounded-2xl p-5 h-fit">
        <p class="text-xs font-semibold uppercase tracking-wide text-muted mb-3">Context</p>
        <dl class="text-sm space-y-3">
            @if($message->page_url)
                <div>
                    <dt class="text-xs text-muted font-semibold">Page</dt>
                    <dd class="mt-0.5"><a href="{{ $message->page_url }}" target="_blank" rel="noopener" class="text-primary hover:underline break-all">{{ $message->page_url }}</a></dd>
                </div>
            @endif
            @if($message->ip)
                <div>
                    <dt class="text-xs text-muted font-semibold">IP</dt>
                    <dd class="mt-0.5 font-mono text-xs">{{ $message->ip }}</dd>
                </div>
            @endif
            @if($message->user_agent)
                <div>
                    <dt class="text-xs text-muted font-semibold">User agent</dt>
                    <dd class="mt-0.5 text-xs text-muted break-words">{{ $message->user_agent }}</dd>
                </div>
            @endif
            <div>
                <dt class="text-xs text-muted font-semibold">Source</dt>
                <dd class="mt-0.5"><span class="px-1.5 py-0.5 rounded-md bg-bgmain text-[11px] uppercase tracking-wide font-semibold">{{ $message->source }}</span></dd>
            </div>
        </dl>
    </aside>
</div>
@endsection
