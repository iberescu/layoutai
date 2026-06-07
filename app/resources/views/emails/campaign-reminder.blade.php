@php /** @var \App\Models\User $user */
    $firstName = trim(explode(' ', $user->name)[0] ?? $user->name);
    $dashboard = url('/dashboard');
@endphp

@component('emails.layout', [
    'eyebrow'     => 'Reminder',
    'title'       => 'Your $500 ad credit is waiting, ' . $firstName . '.',
    'previewText' => "It's been a few days. Your free $500 promotional credit expires in {$daysLeft} days.",
])
@slot('body')
    <p style="margin:0 0 14px 0;">
        Quick nudge — you've got a <strong>$500 promotional ad credit</strong> sitting in your wallet, and your 1,000 generated ads are ready to push live. The credit expires in <strong>{{ $daysLeft }} days</strong>, then it's gone.
    </p>

    <p style="margin:14px 0;">
        The hardest part is already done. The platform has already scored every ad with Gemini, so you don't need to pick winners — sort by score, hit launch, and we'll run the top 30 on Google Display Network on your free budget.
    </p>

    <p style="margin:22px 0;text-align:center;">
        <a href="{{ $dashboard }}" style="display:inline-block;padding:13px 26px;background:linear-gradient(135deg,#2563EB,#7C3AED);color:#FFFFFF;text-decoration:none;border-radius:10px;font-weight:700;font-size:14px;">Launch with my $500 credit →</a>
    </p>

    <p style="margin:18px 0 0 0;color:#64748B;font-size:14px;">
        Don't want this? Reply with "no thanks" and we'll stop the lifecycle emails for your account.
    </p>
@endslot
@slot('footnote')
    Sent because you have an unused promotional credit on the account at {{ $user->email }}. This is the last reminder.
@endslot
@endcomponent
