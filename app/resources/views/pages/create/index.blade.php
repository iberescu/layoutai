@extends('layouts.marketing')

@section('content')
<section id="cta" class="relative overflow-hidden">
    <div class="max-w-7xl mx-auto px-6 py-20 grid lg:grid-cols-2 gap-12 items-center">
        <div>
            <span class="inline-block px-3 py-1 rounded-full bg-accent/10 text-accent text-xs font-semibold mb-5">AI ad generation</span>
            <h1 class="text-5xl md:text-6xl font-extrabold tracking-tight leading-[1.05]">
                Generate <span class="text-primary">1,000 ads</span> for your business.
            </h1>
            <p class="mt-5 text-lg text-muted max-w-lg">
                We test them across display inventory and find the best 10. Daily ads adapt to news, holidays, weather, local events, and seasonal trends.
            </p>

            <div class="mt-8" x-data="createCampaignForm()">
                <button @click="open = true" class="inline-flex items-center gap-2 bg-primary hover:bg-primary/90 text-white font-semibold px-6 py-3.5 rounded-xl shadow-lg shadow-primary/20 transition">
                    Generate my first ads
                    <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
                </button>
                <p class="mt-3 text-sm text-muted">$500 in free ad credits. No credit card required.</p>

                @include('pages.create.partials.modal')
            </div>
        </div>

        <div class="relative h-[460px]">
            <div class="absolute inset-0 grid grid-cols-3 gap-4 -rotate-3 scale-95">
                @for($i = 0; $i < 9; $i++)
                    <div class="bg-surface border border-line rounded-xl shadow-sm aspect-[4/5] overflow-hidden flex flex-col">
                        <div class="flex-1 bg-gradient-to-br from-primary/{{ 20 + ($i*5) }} to-accent/{{ 20 + ($i*5) }}"></div>
                        <div class="p-3">
                            <div class="h-2 bg-line rounded w-3/4 mb-2"></div>
                            <div class="h-2 bg-line rounded w-1/2"></div>
                        </div>
                    </div>
                @endfor
            </div>
        </div>
    </div>
</section>

<section id="how" class="max-w-7xl mx-auto px-6 py-20">
    <h2 class="text-3xl md:text-4xl font-bold mb-12 text-center">How it works</h2>
    <div class="grid md:grid-cols-5 gap-6">
        @foreach([
            ['Enter site', 'We crawl your website and pull brand signals.'],
            ['Learn brand', 'Gemini Flash builds a structured brand profile.'],
            ['Generate ads', '1,000 variants across copy, layout, and CTAs.'],
            ['Test', 'Small budgets find your best 100, then top 30, then 10.'],
            ['Pick winners', 'Top performers go live across partner inventory.'],
        ] as $i => $step)
            <div class="p-5 rounded-2xl border border-line bg-surface">
                <div class="w-9 h-9 rounded-lg bg-primary/10 text-primary font-bold flex items-center justify-center mb-3">{{ $i+1 }}</div>
                <h3 class="font-semibold mb-1">{{ $step[0] }}</h3>
                <p class="text-sm text-muted">{{ $step[1] }}</p>
            </div>
        @endforeach
    </div>
</section>

<section class="max-w-7xl mx-auto px-6 pb-20">
    <div class="rounded-3xl bg-gradient-to-br from-primary to-accent text-white p-10 md:p-14 flex flex-col md:flex-row items-center gap-8">
        <div class="flex-1">
            <h3 class="text-3xl font-bold mb-2">$500 in free ad credits</h3>
            <p class="text-white/85 max-w-xl">
                No credit card required to start. Credits are applied to campaigns run through Layout.ai partner display inventory, including eligible Google Display Network inventory where available.
            </p>
        </div>
        <a href="#cta" class="bg-white text-primary font-semibold px-6 py-3 rounded-xl">Claim credit</a>
    </div>
</section>

<section id="examples" class="max-w-7xl mx-auto px-6 pb-20">
    <h2 class="text-3xl font-bold mb-8">Ad examples across formats</h2>
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        @foreach(['300x250','336x280','728x90','970x250','160x600','300x600','320x50','468x60'] as $size)
            @php [$w, $h] = explode('x', $size); @endphp
            <div class="bg-surface border border-line rounded-xl overflow-hidden shadow-sm">
                <div style="aspect-ratio: {{ $w }}/{{ $h }};" class="bg-gradient-to-br from-primary/20 to-accent/30"></div>
                <div class="p-3 text-xs text-muted">{{ $size }}</div>
            </div>
        @endforeach
    </div>
</section>

<section id="daily" class="max-w-7xl mx-auto px-6 pb-20">
    <h2 class="text-3xl font-bold mb-3">Daily ads from public moments</h2>
    <p class="text-muted mb-8 max-w-2xl">Ads triggered by tasteful, relevant news, events, holidays, location signals, and seasonal trends. Risky topics are filtered out automatically.</p>
    <div class="space-y-3">
        @foreach([
            ['Weather', 'Rainy week in Bucharest', 'Coffee shop ad: Warm up with fresh roasted coffee.'],
            ['Seasonal', 'End of quarter', 'SaaS ad: Close the quarter with cleaner reporting.'],
            ['Holiday', 'Mother\'s Day season', 'Flower shop: Send a premium bouquet before the weekend rush.'],
        ] as $row)
            <div class="flex items-center gap-4 p-4 bg-surface border border-line rounded-xl">
                <span class="px-2 py-1 text-xs bg-accent/10 text-accent rounded">{{ $row[0] }}</span>
                <span class="font-medium">{{ $row[1] }}</span>
                <span class="text-muted text-sm flex-1">→ {{ $row[2] }}</span>
            </div>
        @endforeach
    </div>
</section>

<section id="faq" class="max-w-3xl mx-auto px-6 pb-20" x-data="{ open: 0 }">
    <h2 class="text-3xl font-bold mb-8 text-center">FAQ</h2>
    <div class="space-y-3">
        @foreach([
            ['How is the $500 credit applied?', 'As a Layout.ai promotional credit for campaigns run through Layout.ai partner inventory, including eligible Google Display Network inventory where available.'],
            ['Do I need a credit card to start?', 'No. You can review the generated ads before anything runs.'],
            ['Which networks do you support?', 'Display inventory through Layout.ai partners; HTML5 export is on the roadmap.'],
            ['What about tracking?', 'Layout.ai provides a tracking pixel. Event check is the reliable source of truth.'],
            ['Do you support product feeds?', 'Yes — CSV upload, XML feed URL, and Google Merchant Center XML at MVP. Shopify and WooCommerce next.'],
            ['When am I billed?', 'Never during the free credit. Paid plans only after you decide to scale.'],
        ] as $i => $row)
            <div class="border border-line bg-surface rounded-xl">
                <button @click="open = (open === {{ $i }} ? null : {{ $i }})" class="w-full px-5 py-4 flex items-center justify-between text-left">
                    <span class="font-medium">{{ $row[0] }}</span>
                    <span class="text-muted text-lg" x-text="open === {{ $i }} ? '−' : '+'"></span>
                </button>
                <div x-show="open === {{ $i }}" x-transition class="px-5 pb-4 text-muted text-sm">{{ $row[1] }}</div>
            </div>
        @endforeach
    </div>
</section>

<script>
function createCampaignForm() {
    return {
        open: false,
        loading: false,
        websiteUrl: '',
        businessLocation: '',
        campaignGoal: 'awareness',
        logoPreview: null,
        error: null,
        previewLogo(event) {
            const file = event.target.files[0];
            if (!file) return;
            this.logoPreview = URL.createObjectURL(file);
        },
        async submit(event) {
            this.error = null;
            this.loading = true;
            const form = event.target;
            const data = new FormData(form);
            data.append('website_url', this.websiteUrl);
            data.append('business_location', this.businessLocation);
            data.append('campaign_goal', this.campaignGoal);
            try {
                const res = await fetch('{{ route('create.start') }}', {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Accept': 'application/json' },
                    body: data,
                });
                const payload = await res.json();
                if (!res.ok) throw new Error(payload.message || 'Could not start.');
                window.location.href = payload.redirect;
            } catch (err) {
                this.error = err.message;
                this.loading = false;
            }
        },
    };
}
</script>
@endsection
