@php /** @var \App\Models\SupportMessage $msg */ @endphp
<!doctype html>
<html>
<body style="font-family:-apple-system,'Segoe UI',sans-serif;color:#0F172A;background:#F8FAFC;padding:24px;margin:0;">
    <div style="max-width:560px;margin:0 auto;background:#fff;border:1px solid #E2E8F0;border-radius:14px;overflow:hidden;">
        <div style="padding:18px 22px;background:linear-gradient(135deg,#2563EB,#7C3AED);color:#fff;">
            <p style="margin:0;font-size:12px;letter-spacing:.08em;text-transform:uppercase;opacity:.85;">Layout.ai support</p>
            <p style="margin:4px 0 0 0;font-size:18px;font-weight:700;">New message #{{ $msg->id }}</p>
        </div>
        <div style="padding:22px;">
            <table cellspacing="0" cellpadding="0" style="width:100%;font-size:13px;color:#64748B;margin-bottom:12px;">
                <tr><td style="padding:3px 0;"><strong style="color:#0F172A;">From:</strong></td><td>{{ $msg->email }}@if($msg->user) (user #{{ $msg->user->id }} — {{ $msg->user->name }})@endif</td></tr>
                @if($msg->page_url)
                    <tr><td style="padding:3px 0;"><strong style="color:#0F172A;">Page:</strong></td><td><a href="{{ $msg->page_url }}" style="color:#2563EB;">{{ str($msg->page_url)->limit(80) }}</a></td></tr>
                @endif
                <tr><td style="padding:3px 0;"><strong style="color:#0F172A;">When:</strong></td><td>{{ $msg->created_at->format('Y-m-d H:i') }} UTC</td></tr>
                @if($msg->ip)
                    <tr><td style="padding:3px 0;"><strong style="color:#0F172A;">IP:</strong></td><td>{{ $msg->ip }}</td></tr>
                @endif
            </table>
            <div style="border-top:1px solid #E2E8F0;margin-top:12px;padding-top:14px;">
                <pre style="white-space:pre-wrap;font-family:inherit;font-size:14px;line-height:1.55;color:#0F172A;margin:0;">{{ $msg->body }}</pre>
            </div>
            <p style="margin-top:18px;font-size:12px;color:#64748B;">
                Reply directly to this email to respond to {{ $msg->email }}. Or
                <a href="{{ url('/admin/support') }}" style="color:#2563EB;">open the admin inbox</a>.
            </p>
        </div>
    </div>
</body>
</html>
