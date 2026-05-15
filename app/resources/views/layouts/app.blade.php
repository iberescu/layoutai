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
<body class="bg-bgmain text-ink font-sans antialiased min-h-screen flex">

    <aside class="w-64 bg-surface border-r border-line min-h-screen p-6 hidden md:block">
        <a href="{{ route('dashboard') }}" class="flex items-center mb-10">
            <img src="{{ asset('img/logo.png') }}" alt="Layout.ai — Sell more" class="h-9 w-auto">
        </a>
        <nav class="space-y-1 text-sm">
            @php
                $nav = [
                    ['route' => 'dashboard', 'label' => 'Overview'],
                    ['route' => 'integrations.index', 'label' => 'Integrations'],
                    ['route' => 'reporting.index', 'label' => 'Reporting'],
                    ['route' => 'settings.index', 'label' => 'Settings'],
                ];
            @endphp
            @foreach($nav as $item)
                @php $active = request()->routeIs($item['route']); @endphp
                <a href="{{ route($item['route']) }}"
                   class="block px-3 py-2 rounded-lg {{ $active ? 'bg-primary/10 text-primary font-semibold' : 'text-muted hover:bg-bgmain hover:text-ink' }}">
                    {{ $item['label'] }}
                </a>
            @endforeach
        </nav>
    </aside>

    <div class="flex-1 flex flex-col min-w-0">
        <header class="h-16 border-b border-line bg-surface flex items-center justify-between px-6">
            <h1 class="text-lg font-semibold">{{ $heading ?? 'Dashboard' }}</h1>
            <div class="flex items-center gap-4 text-sm">
                @auth
                    <span class="text-muted">{{ auth()->user()->email }}</span>
                    <form method="POST" action="{{ route('logout') }}">@csrf
                        <button class="text-muted hover:text-ink">Log out</button>
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
