<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Layout.ai Dashboard' }}</title>
    <script src="https://cdn.tailwindcss.com?plugins=forms,typography"></script>
    <script>
        tailwind.config = {
            theme: { extend: { colors: {
                bgmain:  '#F8FAFC',  surface: '#FFFFFF',
                ink:     '#0F172A',  muted:   '#64748B',
                primary: '#2563EB',  accent:  '#7C3AED',
                success: '#10B981',  warning: '#F59E0B',
                line:    '#E2E8F0',
            } } },
        };
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body class="bg-bgmain text-ink font-sans antialiased h-screen flex overflow-hidden">

@php
    $user      = auth()->user();
    $workspace = $user?->primaryWorkspace();
    $latestBrand = $workspace?->campaigns()->with('brandProfile')->latest()->first()?->brandProfile;
    $creditCents = $workspace?->creditBalanceCents() ?? 0;
    $creditDollars = $creditCents / 100;
@endphp

    <aside class="w-64 bg-surface border-r border-line h-full flex flex-col hidden md:flex shrink-0">
        <a href="{{ route('dashboard') }}" class="flex items-center px-6 pt-6 pb-3">
            <img src="{{ asset('img/logo.png') }}" alt="Layout.ai — Sell more" class="h-9 w-auto">
        </a>

        @if($workspace)
        <div class="mx-3 mt-1 mb-4 px-3 py-3 rounded-xl bg-bgmain border border-line">
            <div class="flex items-center gap-2.5 min-w-0">
                @if($latestBrand)
                    <span class="inline-block w-8 h-8 rounded-lg shrink-0 border border-line" style="background: linear-gradient(135deg, {{ $latestBrand->primaryColor() }}, {{ $latestBrand->accentColor() }});"></span>
                @else
                    <span class="inline-block w-8 h-8 rounded-lg shrink-0 border border-line bg-gradient-to-br from-primary to-accent"></span>
                @endif
                <div class="min-w-0">
                    <p class="text-[11px] text-muted uppercase tracking-wide font-medium">Workspace</p>
                    <p class="text-sm font-semibold truncate">{{ $workspace->name }}</p>
                </div>
            </div>
        </div>
        @endif

        <nav class="px-3 space-y-0.5 text-sm flex-1">
            @php
                $nav = [
                    ['route' => 'dashboard',          'label' => 'Overview',     'icon' => 'home'],
                    ['route' => 'integrations.index', 'label' => 'Integrations', 'icon' => 'plug'],
                    ['route' => 'reporting.index',    'label' => 'Reporting',    'icon' => 'chart'],
                    ['route' => 'settings.index',     'label' => 'Settings',     'icon' => 'cog'],
                ];
            @endphp
            @foreach($nav as $item)
                @php $active = request()->routeIs($item['route']); @endphp
                <a href="{{ route($item['route']) }}"
                   class="group flex items-center gap-2.5 px-3 py-2 rounded-lg transition {{ $active ? 'bg-primary/10 text-primary font-semibold' : 'text-muted hover:bg-bgmain hover:text-ink' }}">
                    <span class="w-4 h-4 flex items-center justify-center shrink-0 {{ $active ? 'text-primary' : 'text-muted group-hover:text-ink' }}">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            @switch($item['icon'])
                                @case('home')  <path d="M3 11l9-8 9 8M5 10v10h14V10"/> @break
                                @case('plug')  <path d="M9 2v6M15 2v6M6 8h12v3a6 6 0 0 1-12 0V8zM12 17v5"/> @break
                                @case('chart') <path d="M3 3v18h18"/><path d="M7 14l4-4 3 3 5-6"/> @break
                                @case('cog')   <circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/> @break
                            @endswitch
                        </svg>
                    </span>
                    <span class="flex-1">{{ $item['label'] }}</span>
                    @if($active)
                        <span class="w-1 h-4 rounded-full bg-primary"></span>
                    @endif
                </a>
            @endforeach
        </nav>

        @if($workspace)
        <div class="m-3 p-3 rounded-xl border border-line bg-gradient-to-br from-success/5 to-success/10">
            <div class="flex items-center justify-between mb-1">
                <p class="text-[11px] text-muted uppercase tracking-wide font-semibold">Credit balance</p>
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="text-success">
                    <path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>
                </svg>
            </div>
            <p class="text-lg font-bold text-ink" style="font-variant-numeric: tabular-nums;">
                <span class="text-muted text-sm align-top mr-0.5">$</span>{{ number_format($creditDollars, 2) }}
            </p>
            @if($creditDollars > 0)
                <p class="text-[11px] text-muted mt-0.5">Promotional · 90-day</p>
            @endif
        </div>
        @endif
    </aside>

    <div class="flex-1 flex flex-col min-w-0">
        <header class="h-16 border-b border-line bg-surface flex items-center justify-between px-6">
            <h1 class="text-lg font-semibold tracking-tight">{{ $heading ?? 'Dashboard' }}</h1>
            <div class="flex items-center gap-4 text-sm">
                @auth
                    <span class="text-muted hidden sm:inline">{{ auth()->user()->email }}</span>
                    <form method="POST" action="{{ route('logout') }}">@csrf
                        <button class="text-muted hover:text-ink transition">Log out</button>
                    </form>
                @endauth
            </div>
        </header>
        <main class="flex-1 p-6 overflow-auto">
            @if (session('status'))
                <div class="mb-6 p-3 rounded-lg bg-success/10 text-success text-sm">{{ session('status') }}</div>
            @endif
            {{ $slot ?? '' }}
            @yield('content')
        </main>
    </div>

</body>
</html>
