@extends('layouts.marketing')

@section('content')
<section class="max-w-md mx-auto px-6 py-16">
    <h1 class="text-3xl font-bold mb-2">Claim your $500 ad credit</h1>
    <p class="text-muted mb-8">Create your account and we'll unlock your first 100-ad campaign.</p>

    <form method="POST" action="{{ route('create.claim.store', $session->uuid) }}" class="bg-surface border border-line rounded-2xl p-6 space-y-4 shadow-sm">
        @csrf
        @if($errors->any())
            <div class="text-sm text-red-600">
                <ul class="list-disc list-inside">
                    @foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach
                </ul>
            </div>
        @endif

        <div>
            <label class="block text-sm font-medium mb-1.5">Full name</label>
            <input name="name" required value="{{ old('name') }}" class="w-full rounded-xl border-line focus:ring-primary focus:border-primary">
        </div>
        <div>
            <label class="block text-sm font-medium mb-1.5">Work email</label>
            <input type="email" name="email" required value="{{ old('email') }}" class="w-full rounded-xl border-line focus:ring-primary focus:border-primary">
        </div>
        <div>
            <label class="block text-sm font-medium mb-1.5">Password</label>
            <input type="password" name="password" required minlength="8" class="w-full rounded-xl border-line focus:ring-primary focus:border-primary">
        </div>
        <div>
            <label class="block text-sm font-medium mb-1.5">Company name</label>
            <input name="company_name" value="{{ old('company_name', $brand->company_name ?? '') }}" class="w-full rounded-xl border-line focus:ring-primary focus:border-primary">
        </div>
        <button class="w-full bg-primary text-white font-semibold py-3 rounded-xl">Claim $500 credit</button>
        <p class="text-xs text-muted text-center">No credit card required. Credits apply to campaigns run through Layout.ai partner inventory.</p>
    </form>
</section>
@endsection
