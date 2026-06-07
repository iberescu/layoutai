@php /** @var \App\Models\User $user */
    $firstName = trim(explode(' ', $user->name)[0] ?? $user->name);
    $dashboard = url('/dashboard');
@endphp

@component('emails.layout', [
    'eyebrow'     => 'You\'re in',
    'title'       => 'Welcome to Layout.ai, ' . $firstName . '.',
    'previewText' => 'Your account is ready, 1,000 ads have been generated, and a $500 ad credit is in your wallet.',
])
@slot('body')
    <p style="margin:0 0 14px 0;">
        Your account is set up and your first 1,000 display ads are sitting in your dashboard right now,
        ready to review. We also dropped a <strong>$500 promotional credit</strong> into your wallet so you
        can start testing on live Google Display inventory without putting in a card.
    </p>

    <p style="margin:16px 0 14px 0;font-weight:600;">Here's what's waiting:</p>
    <ul style="margin:0 0 18px 18px;padding:0;color:#334155;">
        <li style="margin-bottom:6px;">1,000+ AI-generated display ads across the 10 IAB sizes</li>
        <li style="margin-bottom:6px;">Each ad scored 0–100 by Gemini on brand-fit, clarity, copy strength and visual appeal</li>
        <li style="margin-bottom:6px;">A $500 ad-spend credit that expires in 90 days</li>
    </ul>

    <p style="margin:18px 0;text-align:center;">
        <a href="{{ $dashboard }}" style="display:inline-block;padding:12px 24px;background:#2563EB;color:#FFFFFF;text-decoration:none;border-radius:10px;font-weight:600;font-size:14px;">Open your dashboard →</a>
    </p>

    <p style="margin:18px 0 0 0;color:#64748B;font-size:14px;">
        Questions? Hit reply, or use the chat bubble on any page. We read every message.
    </p>
@endslot
@slot('footnote')
    You're receiving this because you created a Layout.ai account at {{ $user->email }}.
@endslot
@endcomponent
