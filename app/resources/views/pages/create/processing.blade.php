@extends('layouts.marketing')

@section('content')
<section class="max-w-3xl mx-auto px-6 py-20"
    x-data="processingPoller('{{ route('create.status', $session->uuid) }}', '{{ route('create.preview', $session->uuid) }}')"
    x-init="start()">
    <div class="bg-surface border border-line rounded-3xl shadow-xl p-8 md:p-12">
        <div class="flex items-center gap-4 mb-8">
            <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-primary to-accent text-white flex items-center justify-center font-bold">AI</div>
            <div>
                <h1 class="text-2xl font-bold">Building your first ads</h1>
                <p class="text-muted text-sm">This usually takes 60–120 seconds.</p>
            </div>
        </div>

        <div class="h-2 bg-bgmain rounded-full overflow-hidden mb-8">
            <div class="h-full bg-gradient-to-r from-primary to-accent transition-all" :style="`width: ${progress * 100}%`"></div>
        </div>

        <ul class="space-y-3">
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

        <div class="mt-8 grid grid-cols-2 md:grid-cols-3 gap-3">
            @for ($i = 0; $i < 6; $i++)
                <div class="aspect-[4/5] rounded-xl bg-bgmain animate-pulse"></div>
            @endfor
        </div>
    </div>
</section>

<script>
function processingPoller(statusUrl, previewUrl) {
    return {
        progress: 0,
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
                // Jump to the preview the moment variants exist — the preview
                // page will live-update tiles as each render lands.
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
