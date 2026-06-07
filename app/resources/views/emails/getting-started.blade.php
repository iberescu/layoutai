@php /** @var \App\Models\User $user */
    $firstName = trim(explode(' ', $user->name)[0] ?? $user->name);
    $integrationsUrl = url('/dashboard/integrations');
    $campaignsUrl    = url('/dashboard');
    $supportUrl      = url('/dashboard');
@endphp

@component('emails.layout', [
    'eyebrow'     => 'Get started',
    'title'       => 'Three quick steps, ' . $firstName . '.',
    'previewText' => 'Connect your tracking pixel, pick winners, launch your first campaign with the $500 credit.',
])
@slot('body')
    <p style="margin:0 0 18px 0;">
        Your ads are ready. To start measuring real performance and using the $500 credit, three things stand between you and your first campaign:
    </p>

    {{-- Step 1: tracking pixel --}}
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 12px 0;border:1px solid #E2E8F0;border-radius:12px;">
        <tr>
            <td style="padding:16px 18px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                    <tr>
                        <td width="36" valign="top" style="padding-right:12px;">
                            <span style="display:inline-block;width:30px;height:30px;border-radius:8px;background:#2563EB;color:#FFFFFF;text-align:center;line-height:30px;font-weight:700;font-size:14px;">1</span>
                        </td>
                        <td valign="top">
                            <p style="margin:0 0 4px 0;font-weight:700;font-size:15px;">Install the tracking pixel</p>
                            <p style="margin:0;color:#64748B;font-size:13px;line-height:1.55;">
                                Drop a 1-line script tag on your site. We use it to attribute every click, conversion, and dollar back to the ad that earned it — that's how the platform learns which creatives actually move the needle.
                            </p>
                            <p style="margin:10px 0 0 0;">
                                <a href="{{ $integrationsUrl }}" style="color:#2563EB;font-weight:600;text-decoration:none;font-size:13px;">Get my pixel snippet →</a>
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    {{-- Step 2: review + pick winners --}}
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 12px 0;border:1px solid #E2E8F0;border-radius:12px;">
        <tr>
            <td style="padding:16px 18px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                    <tr>
                        <td width="36" valign="top" style="padding-right:12px;">
                            <span style="display:inline-block;width:30px;height:30px;border-radius:8px;background:#7C3AED;color:#FFFFFF;text-align:center;line-height:30px;font-weight:700;font-size:14px;">2</span>
                        </td>
                        <td valign="top">
                            <p style="margin:0 0 4px 0;font-weight:700;font-size:15px;">Review your top-scored ads</p>
                            <p style="margin:0;color:#64748B;font-size:13px;line-height:1.55;">
                                Each ad is rated 0–100 by Gemini on clarity, brand fit, copy strength, CTA visibility, and visual appeal. Sort by score on the campaign page and skim the top 30 — those are your launch candidates.
                            </p>
                            <p style="margin:10px 0 0 0;">
                                <a href="{{ $campaignsUrl }}" style="color:#7C3AED;font-weight:600;text-decoration:none;font-size:13px;">Open the campaign →</a>
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    {{-- Step 3: launch with $500 --}}
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 18px 0;border:1px solid #E2E8F0;border-radius:12px;background:linear-gradient(135deg,rgba(16,185,129,0.08),rgba(124,58,237,0.05));">
        <tr>
            <td style="padding:16px 18px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                    <tr>
                        <td width="36" valign="top" style="padding-right:12px;">
                            <span style="display:inline-block;width:30px;height:30px;border-radius:8px;background:#10B981;color:#FFFFFF;text-align:center;line-height:30px;font-weight:700;font-size:14px;">3</span>
                        </td>
                        <td valign="top">
                            <p style="margin:0 0 4px 0;font-weight:700;font-size:15px;">Launch with your $500 credit</p>
                            <p style="margin:0;color:#64748B;font-size:13px;line-height:1.55;">
                                Push the top 30 ads onto Google Display Network from your dashboard. Your $500 promotional credit covers the test budget — no card needed. The credit expires 90 days from signup.
                            </p>
                            <p style="margin:10px 0 0 0;">
                                <a href="{{ $campaignsUrl }}" style="color:#10B981;font-weight:600;text-decoration:none;font-size:13px;">Run my first campaign →</a>
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <p style="margin:0;color:#64748B;font-size:14px;">
        Need a hand on any step? Reply to this email or use the chat bubble on the dashboard.
    </p>
@endslot
@slot('footnote')
    Sent because you signed up at {{ $user->email }}. One more nudge if you haven't launched a campaign in a few days, then we stop.
@endslot
@endcomponent
