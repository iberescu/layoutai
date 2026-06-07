@php /** @var string $previewText */ @endphp
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{{ $title ?? 'Layout.ai' }}</title>
</head>
<body style="margin:0;padding:0;background:#F8FAFC;font-family:-apple-system,'Segoe UI','Inter',sans-serif;color:#0F172A;">
    @isset($previewText)
        <div style="display:none;max-height:0;overflow:hidden;color:transparent;opacity:0;font-size:1px;line-height:1px;">{{ $previewText }}</div>
    @endisset

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:#F8FAFC;padding:32px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="560" style="max-width:560px;background:#FFFFFF;border:1px solid #E2E8F0;border-radius:16px;overflow:hidden;">
                    <tr>
                        <td style="padding:24px 28px;background:linear-gradient(135deg,#2563EB,#7C3AED);color:#FFFFFF;">
                            <p style="margin:0;font-size:12px;letter-spacing:.12em;text-transform:uppercase;opacity:.8;font-weight:600;">{{ $eyebrow ?? 'Layout.ai' }}</p>
                            <p style="margin:6px 0 0 0;font-size:22px;font-weight:800;line-height:1.25;">{{ $title }}</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:26px 28px;font-size:15px;line-height:1.6;color:#0F172A;">
                            {!! $body !!}
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:18px 28px 26px 28px;border-top:1px solid #E2E8F0;font-size:12px;line-height:1.55;color:#64748B;">
                            <p style="margin:0;">
                                Sent by Layout.ai &middot; <a href="https://layout.ai" style="color:#2563EB;text-decoration:none;">layout.ai</a>
                                &middot; <a href="mailto:support@layout.ai" style="color:#2563EB;text-decoration:none;">support@layout.ai</a>
                            </p>
                            @isset($footnote)
                                <p style="margin:8px 0 0 0;color:#94A3B8;">{{ $footnote }}</p>
                            @endisset
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
