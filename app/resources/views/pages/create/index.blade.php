@extends('layouts.marketing')

@section('content')
{{-- Sticky $500 banner --}}
<div class="bg-gradient-to-r from-primary via-indigo-600 to-accent text-white">
    <div class="max-w-7xl mx-auto px-6 py-2.5 flex items-center justify-center gap-2 text-sm font-medium">
        <span class="inline-flex items-center justify-center w-5 h-5 rounded-full bg-white/20 text-[10px] font-bold">$</span>
        <span><strong>$500 in free ad credits</strong> for your first campaign – no credit card required.</span>
        <a href="#cta" class="ml-2 underline underline-offset-4 decoration-white/50 hover:decoration-white">Claim now →</a>
    </div>
</div>

<section id="cta" class="relative overflow-hidden" x-data="createCampaignForm()">
    {{-- Soft background flourish --}}
    <div class="absolute -top-32 -right-32 w-[600px] h-[600px] rounded-full bg-gradient-to-br from-primary/20 to-accent/20 blur-3xl pointer-events-none"></div>
    <div class="absolute -bottom-40 -left-20 w-[500px] h-[500px] rounded-full bg-gradient-to-br from-accent/15 to-pink-300/20 blur-3xl pointer-events-none"></div>

    <div class="max-w-7xl mx-auto px-6 pt-20 pb-24 grid lg:grid-cols-12 gap-12 items-center relative">
        <div class="lg:col-span-7">
            <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-white border border-line shadow-sm mb-6">
                <span class="w-2 h-2 rounded-full bg-success animate-pulse"></span>
                <span class="text-xs font-semibold tracking-wide text-ink/70 uppercase">Live AI ad engine</span>
            </div>
            <h1 class="text-5xl md:text-7xl font-extrabold tracking-tight leading-[1.02]">
                Generate <span class="bg-gradient-to-r from-primary to-accent bg-clip-text text-transparent">1,000 ads</span>
                <br class="hidden md:block"> for your business.
            </h1>
            <p class="mt-6 text-lg md:text-xl text-muted max-w-xl leading-relaxed">
                We test them across display inventory and find your <strong class="text-ink">top 10 performers</strong>. Daily ads adapt to news, holidays, weather, local events, and seasonal trends.
            </p>

            <div class="mt-8 flex flex-wrap items-center gap-4">
                <button @click="open = true" class="group inline-flex items-center gap-2.5 bg-ink hover:bg-ink/90 text-white font-semibold px-7 py-4 rounded-2xl shadow-xl shadow-ink/15 transition-all hover:scale-[1.02]">
                    Generate my first ads
                    <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" class="transition-transform group-hover:translate-x-0.5"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
                </button>
                <div class="flex items-center gap-2 text-sm text-muted">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="10"/></svg>
                    $500 free credit · no credit card
                </div>
            </div>

            <div class="mt-10 flex items-center gap-6 text-sm text-muted">
                <div class="flex -space-x-2">
                    @foreach(['F','A','M','K','R'] as $i => $letter)
                        <span class="w-8 h-8 rounded-full border-2 border-white bg-gradient-to-br from-primary to-accent text-white text-xs font-bold flex items-center justify-center">{{ $letter }}</span>
                    @endforeach
                </div>
                <span>Trusted by growth teams shipping <strong class="text-ink">12,000+</strong> ads this month</span>
            </div>

            @include('pages.create.partials.modal')
        </div>

        {{-- Right column: stacked moving ads --}}
        <div class="lg:col-span-5 relative h-[520px]" aria-hidden="true">
            <div class="absolute inset-0 flex items-center justify-center">
                <div class="grid grid-cols-2 gap-4 -rotate-6 scale-95">
                    @php
                        $samples = [
                            ['from' => '#2563EB', 'to' => '#7C3AED', 'headline' => 'Made for your everyday.', 'cta' => 'Order today'],
                            ['from' => '#0EA5E9', 'to' => '#10B981', 'headline' => 'Fresh roasted daily.',     'cta' => 'Shop now'],
                            ['from' => '#F59E0B', 'to' => '#EF4444', 'headline' => 'Limited season picks.',     'cta' => 'See more'],
                            ['from' => '#7C3AED', 'to' => '#EC4899', 'headline' => 'Trusted by locals.',        'cta' => 'Try it free'],
                            ['from' => '#10B981', 'to' => '#2563EB', 'headline' => 'Smarter reporting.',        'cta' => 'Get started'],
                            ['from' => '#1F2937', 'to' => '#6366F1', 'headline' => 'Crafted with care.',        'cta' => 'Learn more'],
                        ];
                    @endphp
                    @foreach($samples as $i => $s)
                        <div class="relative bg-surface border border-line rounded-2xl shadow-xl overflow-hidden aspect-[4/5] {{ $i % 2 === 1 ? 'mt-8' : '' }} hover:scale-105 transition-transform"
                             style="animation: floatUp{{ $i }} 6s ease-in-out infinite alternate; animation-delay: {{ $i * 0.4 }}s">
                            <div class="absolute inset-0" style="background: linear-gradient(135deg, {{ $s['from'] }}, {{ $s['to'] }});"></div>
                            <div class="absolute inset-0 bg-gradient-to-t from-ink/60 via-transparent to-transparent"></div>
                            <div class="absolute inset-0 p-3 flex flex-col justify-end text-white">
                                <p class="font-bold text-xs leading-tight">{{ $s['headline'] }}</p>
                                <span class="inline-block w-fit mt-1.5 px-2 py-0.5 bg-white/95 text-ink rounded-full text-[10px] font-semibold">{{ $s['cta'] }}</span>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
            <style>
                @keyframes floatUp0 { 0% { transform: translateY(0); } 100% { transform: translateY(-12px); } }
                @keyframes floatUp1 { 0% { transform: translateY(0); } 100% { transform: translateY(10px); } }
                @keyframes floatUp2 { 0% { transform: translateY(0); } 100% { transform: translateY(-8px); } }
                @keyframes floatUp3 { 0% { transform: translateY(0); } 100% { transform: translateY(12px); } }
                @keyframes floatUp4 { 0% { transform: translateY(0); } 100% { transform: translateY(-10px); } }
                @keyframes floatUp5 { 0% { transform: translateY(0); } 100% { transform: translateY(8px); } }
            </style>
        </div>
    </div>
</section>

{{-- $500 hero banner (large card) --}}
<section class="max-w-7xl mx-auto px-6 -mt-8 pb-10 relative z-10">
    <div class="relative overflow-hidden rounded-3xl bg-gradient-to-br from-primary via-indigo-600 to-accent text-white p-10 md:p-14 shadow-2xl">
        <div class="absolute -top-20 -right-20 w-80 h-80 rounded-full bg-white/10 blur-3xl"></div>
        <div class="absolute -bottom-32 -left-10 w-72 h-72 rounded-full bg-pink-400/20 blur-3xl"></div>

        <div class="relative grid md:grid-cols-12 gap-8 items-center">
            <div class="md:col-span-7">
                <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-white/15 text-xs font-semibold tracking-wide uppercase mb-5 backdrop-blur">
                    <span>★</span> Limited promotional offer
                </div>
                <h2 class="text-4xl md:text-5xl font-extrabold tracking-tight">
                    Get <span class="text-white">$500</span> in free ad credits.
                </h2>
                <p class="mt-3 text-white/90 max-w-lg text-lg leading-relaxed">
                    Test your first AI-generated campaign on Layout.ai partner display inventory — including eligible Google Display Network inventory where available.
                </p>
                <ul class="mt-6 space-y-2 text-white/95">
                    @foreach(['No credit card required', 'Review every ad before it runs', 'Cancel any time'] as $perk)
                        <li class="flex items-center gap-2">
                            <span class="inline-flex items-center justify-center w-5 h-5 rounded-full bg-white/20 text-white text-[11px] font-bold">✓</span>
                            <span>{{ $perk }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
            <div class="md:col-span-5 flex md:justify-end">
                <a href="#cta" onclick="document.querySelector('#cta button').click(); return false;" class="inline-flex items-center justify-center gap-2 bg-white text-primary font-bold px-8 py-4 rounded-2xl shadow-2xl shadow-black/20 hover:scale-[1.03] transition w-full md:w-auto">
                    Claim my $500 credit
                    <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
                </a>
            </div>
        </div>
    </div>
</section>

{{-- How it works --}}
<section id="how" class="max-w-7xl mx-auto px-6 py-20">
    <div class="text-center mb-14">
        <span class="inline-block px-3 py-1 rounded-full bg-accent/10 text-accent text-xs font-semibold tracking-wide uppercase mb-3">The flow</span>
        <h2 class="text-4xl md:text-5xl font-bold tracking-tight">How Layout.ai builds your ads</h2>
        <p class="text-muted mt-3 max-w-2xl mx-auto">From a single URL to a tested, winning campaign in minutes.</p>
    </div>
    <div class="grid md:grid-cols-5 gap-5">
        @foreach([
            ['icon' => 'globe',    'title' => 'Enter site',     'desc' => 'We crawl your website and pull brand signals.'],
            ['icon' => 'sparkles', 'title' => 'Learn brand',    'desc' => 'Gemini Flash builds a structured brand profile.'],
            ['icon' => 'grid',     'title' => 'Generate ads',   'desc' => '1,000 variants across copy, layout, and CTAs.'],
            ['icon' => 'beaker',   'title' => 'Test',           'desc' => 'Small budgets find your best 100, then top 30.'],
            ['icon' => 'trophy',   'title' => 'Pick winners',   'desc' => 'Top 10 go live across partner inventory.'],
        ] as $i => $step)
            <div class="group relative p-6 rounded-2xl border border-line bg-surface hover:shadow-xl hover:-translate-y-1 transition-all">
                <div class="absolute top-4 right-4 text-xs font-bold text-muted">0{{ $i+1 }}</div>
                <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-primary to-accent text-white flex items-center justify-center mb-4">
                    <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        @switch($step['icon'])
                            @case('globe') <circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15 15 0 0 1 0 20M12 2a15 15 0 0 0 0 20"/> @break
                            @case('sparkles') <path d="M12 3l1.9 5.6L19.5 10l-5.6 1.9L12 17.5l-1.9-5.6L4.5 10l5.6-1.4z"/> @break
                            @case('grid') <rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/> @break
                            @case('beaker') <path d="M9 3v4l-5 9a4 4 0 0 0 4 6h8a4 4 0 0 0 4-6l-5-9V3"/><path d="M9 3h6"/> @break
                            @case('trophy') <path d="M6 9a6 6 0 0 0 12 0V4H6zM4 6h2M18 6h2M12 15v4M9 22h6"/> @break
                        @endswitch
                    </svg>
                </div>
                <h3 class="font-bold mb-1.5">{{ $step['title'] }}</h3>
                <p class="text-sm text-muted leading-relaxed">{{ $step['desc'] }}</p>
            </div>
        @endforeach
    </div>
</section>

{{-- Examples --}}
<section id="examples" class="bg-ink text-white py-20 my-10">
    <div class="max-w-7xl mx-auto px-6">
        <div class="flex flex-col md:flex-row md:items-end justify-between mb-10 gap-4">
            <div>
                <span class="inline-block px-3 py-1 rounded-full bg-white/10 text-xs font-semibold tracking-wide uppercase mb-3">All formats</span>
                <h2 class="text-4xl md:text-5xl font-bold tracking-tight">Every display ad size covered.</h2>
                <p class="text-white/60 mt-2">Generated, rendered, and ready to test in 10 standard IAB sizes.</p>
            </div>
        </div>
        <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-5 gap-4">
            @foreach(['300x250'=>'Medium rectangle','336x280'=>'Large rectangle','728x90'=>'Leaderboard','970x250'=>'Billboard','160x600'=>'Wide skyscraper','300x600'=>'Half page','320x50'=>'Mobile leaderboard','320x100'=>'Large mobile','468x60'=>'Banner','250x250'=>'Square'] as $size => $name)
                @php [$w, $h] = explode('x', $size); @endphp
                <div class="bg-white/5 border border-white/10 rounded-xl overflow-hidden hover:border-white/30 transition">
                    <div style="aspect-ratio: {{ $w }}/{{ $h }};" class="bg-gradient-to-br from-primary/30 via-accent/30 to-pink-500/30"></div>
                    <div class="p-3">
                        <div class="text-xs font-bold">{{ $size }}</div>
                        <div class="text-xs text-white/50">{{ $name }}</div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</section>

{{-- Daily ads --}}
<section id="daily" class="max-w-7xl mx-auto px-6 py-20">
    <div class="text-center mb-12">
        <span class="inline-block px-3 py-1 rounded-full bg-accent/10 text-accent text-xs font-semibold tracking-wide uppercase mb-3">Daily ads</span>
        <h2 class="text-4xl md:text-5xl font-bold tracking-tight">Always-fresh ads from public moments.</h2>
        <p class="text-muted mt-3 max-w-2xl mx-auto">Ads triggered by tasteful, relevant news, events, holidays, weather, and seasonal trends — never tragedies, politics, or sensitive topics.</p>
    </div>
    <div class="grid md:grid-cols-2 gap-4">
        @foreach([
            ['Weather',  'Rainy week in Bucharest',        'Coffee shop: Warm up with fresh roasted coffee.',  '#0EA5E9'],
            ['Seasonal', 'End of quarter',                 'SaaS: Close the quarter with cleaner reporting.',  '#7C3AED'],
            ['Holiday',  "Mother's Day season",            'Florist: Send a premium bouquet before the rush.', '#EC4899'],
            ['Local',    'Marathon weekend',               'Gym: Train smarter all year, not just race week.',  '#10B981'],
        ] as $row)
            <div class="flex items-start gap-4 p-5 bg-surface border border-line rounded-2xl hover:shadow-md transition">
                <span class="px-2 py-1 text-xs font-semibold rounded-md text-white shrink-0" style="background: {{ $row[3] }}">{{ $row[0] }}</span>
                <div>
                    <p class="font-semibold mb-0.5">{{ $row[1] }}</p>
                    <p class="text-sm text-muted">→ {{ $row[2] }}</p>
                </div>
            </div>
        @endforeach
    </div>
</section>

{{-- FAQ --}}
<section id="faq" class="max-w-3xl mx-auto px-6 py-20" x-data="{ open: 0 }">
    <div class="text-center mb-12">
        <span class="inline-block px-3 py-1 rounded-full bg-accent/10 text-accent text-xs font-semibold tracking-wide uppercase mb-3">FAQ</span>
        <h2 class="text-4xl font-bold tracking-tight">Common questions</h2>
    </div>
    <div class="space-y-3">
        @foreach([
            ['How is the $500 credit applied?', 'As a Layout.ai promotional credit for campaigns run through Layout.ai partner inventory, including eligible Google Display Network inventory where available.'],
            ['Do I need a credit card to start?', 'No. You can review the generated ads before anything runs.'],
            ['Which networks do you support?', 'Display inventory through Layout.ai partners; HTML5 export is on the roadmap.'],
            ['What about tracking?', 'Layout.ai provides a tracking pixel. Event check is the reliable source of truth.'],
            ['Do you support product feeds?', 'Yes — CSV upload, XML feed URL, and Google Merchant Center XML at MVP. Shopify and WooCommerce next.'],
            ['When am I billed?', 'Never during the free credit. Paid plans only after you decide to scale.'],
        ] as $i => $row)
            <div class="border border-line bg-surface rounded-2xl overflow-hidden">
                <button @click="open = (open === {{ $i }} ? null : {{ $i }})" class="w-full px-5 py-4 flex items-center justify-between text-left">
                    <span class="font-medium">{{ $row[0] }}</span>
                    <span class="text-primary text-lg font-bold transition-transform" :class="open === {{ $i }} ? 'rotate-45' : ''">+</span>
                </button>
                <div x-show="open === {{ $i }}" x-transition class="px-5 pb-4 text-muted text-sm leading-relaxed">{{ $row[1] }}</div>
            </div>
        @endforeach
    </div>
</section>

{{-- Final CTA --}}
<section class="max-w-7xl mx-auto px-6 pb-24">
    <div class="bg-surface border border-line rounded-3xl p-10 md:p-14 text-center">
        <h2 class="text-3xl md:text-4xl font-bold tracking-tight mb-3">Ready to generate your first 30 ads?</h2>
        <p class="text-muted mb-6 max-w-xl mx-auto">Drop your website URL and we'll build the brand, write the copy, generate the images, and render polished previews — all before you create an account.</p>
        <a href="#cta" onclick="document.querySelector('#cta button').click(); return false;" class="inline-flex items-center gap-2 bg-ink text-white font-semibold px-7 py-3.5 rounded-2xl">
            Start free →
        </a>
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
