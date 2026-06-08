<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Layout.ai – Generate 1,000 ads for your business' }}</title>
    <meta name="description" content="Layout.ai scans your website, learns your brand, and generates display ads tested across partner inventory.">

    {{-- Tailwind via Play CDN keeps the MVP simple. Replace with Vite build in production. --}}
    <script src="https://cdn.tailwindcss.com?plugins=forms,typography"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        bgmain:   '#F8FAFC',
                        surface:  '#FFFFFF',
                        ink:      '#0F172A',
                        muted:    '#64748B',
                        primary:  '#2563EB',
                        accent:   '#7C3AED',
                        success:  '#10B981',
                        warning:  '#F59E0B',
                        line:     '#E2E8F0',
                    },
                    fontFamily: {
                        sans: ['Inter', 'system-ui', 'sans-serif'],
                    },
                },
            },
        };
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body class="bg-bgmain text-ink font-sans antialiased">

    <header class="border-b border-line bg-surface/70 backdrop-blur sticky top-0 z-30">
        <div class="max-w-7xl mx-auto px-6 py-4 flex items-center justify-between">
            <a href="{{ route('create.index') }}" class="flex items-center">
                <img src="{{ asset('img/logo.png') }}?v={{ @filemtime(public_path('img/logo.png')) }}" alt="Layout.ai" class="h-9 w-auto">
            </a>
            <nav class="hidden md:flex items-center gap-8 text-sm text-muted">
                <a href="#pipeline" class="hover:text-ink">How it works</a>
                <a href="#examples" class="hover:text-ink">Examples</a>
                <a href="#daily" class="hover:text-ink">Daily ads</a>
                <a href="#faq" class="hover:text-ink">FAQ</a>
            </nav>
            <div class="flex items-center gap-3">
                @auth
                    <a href="{{ route('dashboard') }}" class="text-sm font-medium hover:text-primary">Dashboard</a>
                @else
                    <a href="#cta" class="text-sm font-medium text-primary">Get started</a>
                @endauth
            </div>
        </div>
    </header>

    <main>
        {{ $slot ?? '' }}
        @yield('content')
    </main>

    <footer class="mt-24 border-t border-line bg-surface">
        <div class="max-w-7xl mx-auto px-6 py-10 text-sm text-muted flex flex-col md:flex-row items-center justify-between gap-3">
            <p>&copy; {{ date('Y') }} Layout.ai. AI-generated ads tested across partner display inventory.</p>
            <p>Credits applied to campaigns run through Layout.ai partner inventory.</p>
        </div>
    </footer>

    <x-support-chat />
</body>
</html>
