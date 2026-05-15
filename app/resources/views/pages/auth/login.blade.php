@extends('layouts.marketing')

@section('content')
<section class="max-w-md mx-auto px-6 py-20">
    <h1 class="text-3xl font-bold mb-2">Log in</h1>
    <p class="text-muted mb-8">Welcome back to Layout.ai.</p>
    <form method="POST" action="{{ route('login') }}" class="bg-surface border border-line rounded-2xl p-6 space-y-4 shadow-sm">
        @csrf
        @error('email') <div class="text-sm text-red-600">{{ $message }}</div> @enderror
        <div>
            <label class="block text-sm font-medium mb-1.5">Email</label>
            <input type="email" name="email" value="{{ old('email') }}" required class="w-full rounded-xl border-line focus:ring-primary focus:border-primary">
        </div>
        <div>
            <label class="block text-sm font-medium mb-1.5">Password</label>
            <input type="password" name="password" required class="w-full rounded-xl border-line focus:ring-primary focus:border-primary">
        </div>
        <label class="flex items-center gap-2 text-sm text-muted">
            <input type="checkbox" name="remember" class="rounded border-line"> Remember me
        </label>
        <button class="w-full bg-primary text-white font-semibold py-3 rounded-xl">Log in</button>
    </form>
</section>
@endsection
