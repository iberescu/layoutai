@extends('layouts.marketing')

@section('content')
<section class="max-w-3xl mx-auto px-6 py-20"
    x-data="processingPoller('{{ route('create.status', $session->uuid) }}', '{{ route('create.preview', $session->uuid) }}', '{{ route('create.index') }}')"
    x-init="start()">
    <div class="bg-surface border border-line rounded-3xl shadow-xl p-8 md:p-12">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-6 mb-8">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-primary to-accent text-white flex items-center justify-center font-bold">AI</div>
                <div>
                    <h1 class="text-2xl font-bold">Building your first ads</h1>
                    <p class="text-muted text-sm">This usually takes 60–120 seconds.</p>
                </div>
            </div>

            {{-- Mini "ad being assembled" mockup. CSS-only loop:
                 image fades in → headline bars slide in → CTA pill pops → score badge appears → reset. --}}
            <div class="ad-build-mock shrink-0 relative w-[220px] h-[140px] rounded-xl overflow-hidden border border-line shadow-md bg-bgmain">
                <div class="abm-image absolute inset-0"></div>
                <div class="abm-overlay absolute inset-0"></div>
                <div class="absolute top-2 right-2 abm-badge inline-flex items-center gap-1 px-1.5 py-0.5 rounded-full bg-ink/85 backdrop-blur text-white shadow-sm">
                    <span class="w-1.5 h-1.5 rounded-full bg-success"></span>
                    <span class="text-[10px] font-bold tabular-nums">87</span>
                </div>
                <div class="absolute left-3 right-3 bottom-3 space-y-1.5">
                    <div class="abm-bar abm-bar-1 h-2.5 rounded-sm bg-white/90 w-[78%]"></div>
                    <div class="abm-bar abm-bar-2 h-1.5 rounded-sm bg-white/60 w-[56%]"></div>
                    <div class="abm-cta inline-flex items-center gap-1 mt-1 px-2.5 py-1 rounded-md bg-accent text-white text-[10px] font-semibold shadow-sm">
                        Learn more
                        <svg viewBox="0 0 12 12" class="w-2.5 h-2.5"><path d="M3 6h6m-3-3 3 3-3 3" stroke="currentColor" stroke-width="1.5" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </div>
                </div>
            </div>
        </div>

        {{-- Failure card: shown when the session status is "failed". Replaces
             the progress + step list below so the user sees the problem
             clearly and can retry with a different URL. --}}
        <div x-show="failedMessage" x-cloak class="rounded-2xl border border-red-200 bg-red-50 p-5 mb-6">
            <div class="flex items-start gap-3">
                <div class="w-8 h-8 rounded-full bg-red-500 text-white flex items-center justify-center shrink-0">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M12 9v4M12 17h.01"/><circle cx="12" cy="12" r="10"/></svg>
                </div>
                <div class="flex-1">
                    <p class="font-semibold text-red-900">We couldn't analyse this website.</p>
                    <p class="text-sm text-red-800 mt-1" x-text="failedMessage"></p>
                    <a :href="retryUrl" class="inline-flex items-center gap-1.5 mt-3 text-sm font-semibold text-red-900 hover:text-red-700 underline">
                        Try a different URL →
                    </a>
                </div>
            </div>
        </div>

        <div x-show="!failedMessage" class="h-2 bg-bgmain rounded-full overflow-hidden mb-8">
            <div class="h-full bg-gradient-to-r from-primary to-accent transition-all" :style="`width: ${progress * 100}%`"></div>
        </div>

        <ul x-show="!failedMessage" class="space-y-3">
            <template x-for="step in steps" :key="step.key">
                <li class="flex items-center gap-3 p-3 rounded-xl"
                    :class="step.status === 'completed' ? 'bg-success/5' : (step.status === 'in_progress' ? 'bg-primary/5' : 'bg-bgmain')">
                    <span class="w-5 h-5 rounded-full flex items-center justify-center text-[10px] font-bold"
                          :class="step.status === 'completed' ? 'bg-success text-white' : (step.status === 'in_progress' ? 'bg-primary text-white' : 'bg-line text-muted')">
                        <span x-show="step.status === 'completed'">✓</span>
                        <span x-show="step.status === 'in_progress'" class="animate-pulse">●</span>
                    </span>
                    <span class="text-sm" :class="step.status === 'pending' ? 'text-muted' : ''" x-text="step.label"></span>
                </li>
            </template>
        </ul>

        <div x-show="!failedMessage" class="mt-8 grid grid-cols-2 md:grid-cols-3 gap-3">
            @for ($i = 0; $i < 6; $i++)
                <div class="aspect-[4/5] rounded-xl bg-bgmain animate-pulse"></div>
            @endfor
        </div>
    </div>
</section>

<style>
    /* "Ad being assembled" loop — six stages on a 5.4s cycle so each element
       appears in turn, holds, then resets. Image and overlay use the brand
       gradient so the mock feels on-brand even while colors are still loading. */
    .ad-build-mock .abm-image {
        background:
            radial-gradient(120% 90% at 80% 20%, rgba(255,255,255,0.35), transparent 55%),
            linear-gradient(135deg, var(--brand-primary, #2563EB), var(--brand-accent, #7C3AED));
        background-size: 200% 200%;
        animation: abm-image-in 5.4s ease-in-out infinite, abm-image-pan 8s ease-in-out infinite;
        opacity: 0;
    }
    .ad-build-mock .abm-overlay {
        background: linear-gradient(180deg, transparent 35%, rgba(15,23,42,0.55) 100%);
        opacity: 0;
        animation: abm-fade-in 5.4s ease-in-out infinite;
        animation-delay: 0.15s;
    }
    .ad-build-mock .abm-bar  { transform: translateX(-12px); opacity: 0; animation: abm-bar-in 5.4s cubic-bezier(0.22,1,0.36,1) infinite; }
    .ad-build-mock .abm-bar-1 { animation-delay: 1.20s; }
    .ad-build-mock .abm-bar-2 { animation-delay: 1.55s; }
    .ad-build-mock .abm-cta   { transform: scale(0.6); opacity: 0; animation: abm-cta-in 5.4s cubic-bezier(0.34,1.56,0.64,1) infinite; animation-delay: 2.10s; }
    .ad-build-mock .abm-badge { transform: scale(0.4) translateY(-4px); opacity: 0; animation: abm-badge-in 5.4s cubic-bezier(0.34,1.56,0.64,1) infinite; animation-delay: 2.80s; }

    @keyframes abm-image-in  {
        0%, 5%  { opacity: 0; }
        15%, 88% { opacity: 1; }
        100%    { opacity: 0; }
    }
    @keyframes abm-image-pan { 0%,100% { background-position: 0% 50%; } 50% { background-position: 100% 50%; } }
    @keyframes abm-fade-in   {
        0%, 8%   { opacity: 0; }
        20%, 88% { opacity: 1; }
        100%     { opacity: 0; }
    }
    @keyframes abm-bar-in    {
        0%, 18%  { transform: translateX(-12px); opacity: 0; }
        28%, 88% { transform: translateX(0);     opacity: 1; }
        100%     { transform: translateX(-12px); opacity: 0; }
    }
    @keyframes abm-cta-in    {
        0%, 35%  { transform: scale(0.6); opacity: 0; }
        45%, 88% { transform: scale(1);   opacity: 1; }
        100%     { transform: scale(0.6); opacity: 0; }
    }
    @keyframes abm-badge-in  {
        0%, 50%  { transform: scale(0.4) translateY(-4px); opacity: 0; }
        58%, 88% { transform: scale(1)   translateY(0);    opacity: 1; }
        100%     { transform: scale(0.4) translateY(-4px); opacity: 0; }
    }
    @media (prefers-reduced-motion: reduce) {
        .ad-build-mock .abm-image, .ad-build-mock .abm-overlay,
        .ad-build-mock .abm-bar,   .ad-build-mock .abm-cta,
        .ad-build-mock .abm-badge { animation: none; opacity: 1; transform: none; }
    }
</style>

<script>
function processingPoller(statusUrl, previewUrl, retryUrl) {
    return {
        progress: 0,
        failedMessage: '',
        retryUrl,
        steps: [
            { key: 'crawl', label: 'Scanning your website', status: 'pending' },
            { key: 'extract_brand', label: 'Finding your logo and brand colors', status: 'pending' },
            { key: 'summarize_brand', label: 'Learning your brand voice', status: 'pending' },
            { key: 'concepts', label: 'Writing ad concepts', status: 'pending' },
            { key: 'image_prompts', label: 'Creating image prompts', status: 'pending' },
            { key: 'images', label: 'Generating ad images', status: 'pending' },
            { key: 'templates', label: 'Designing display ads', status: 'pending' },
            { key: 'render', label: 'Rendering previews', status: 'pending' },
        ],
        start() {
            this.poll();
            this.interval = setInterval(() => this.poll(), 2500);
        },
        async poll() {
            try {
                const res = await fetch(statusUrl, { headers: { Accept: 'application/json' } });
                const data = await res.json();
                this.progress = data.progress;
                this.steps = this.steps.map(s => ({ ...s, status: data.steps[s.key]?.status ?? 'pending' }));
                if (data.status === 'failed') {
                    this.failedMessage = data.error || 'Generation failed. Please try a different URL.';
                    clearInterval(this.interval);
                    return;
                }
                // Jump to the preview the moment variants exist.
                if (['preview_streaming', 'preview_ready', 'completed'].includes(data.status)) {
                    clearInterval(this.interval);
                    window.location.href = previewUrl;
                }
            } catch (e) { /* noop, retry on next tick */ }
        },
    };
}
</script>
@endsection
