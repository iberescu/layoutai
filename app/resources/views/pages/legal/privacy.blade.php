@extends('layouts.marketing')

@section('content')
<div class="max-w-3xl mx-auto px-6 py-16">
    <h1 class="text-4xl font-extrabold tracking-tight text-ink">Privacy Policy</h1>
    <p class="mt-2 text-sm text-muted">Last updated: June 8, 2026</p>

    <div class="prose prose-slate mt-8 max-w-none">
        <p>This Privacy Policy explains how <strong>Layout.ai</strong> ("Layout.ai", "we", "us")
        collects, uses, and protects information when you use <a href="https://layout.ai">layout.ai</a>
        and related services (the "Service").</p>

        <h2>Information we collect</h2>
        <ul>
            <li><strong>Information you provide:</strong> the website URL you submit, any logo or
                brand assets you upload, your campaign goals, and — if you create an account — your
                name and email address.</li>
            <li><strong>Content we generate for you:</strong> brand profiles, ad concepts, and ad
                creatives produced from your inputs.</li>
            <li><strong>Usage &amp; device data:</strong> pages viewed, actions taken, IP address,
                browser/device type, and referring source, collected via cookies and similar
                technologies (including the Meta Pixel) to measure and improve our marketing and
                the Service.</li>
        </ul>

        <h2>How we use information</h2>
        <ul>
            <li>To provide the Service — crawl the website you submit, learn your brand, and
                generate and evaluate ads.</li>
            <li>To create and secure your account and deliver the features you request.</li>
            <li>To measure, attribute, and improve our own advertising and the performance of the
                Service (including conversion measurement).</li>
            <li>To communicate with you about the Service, including transactional and, where
                permitted, marketing messages.</li>
        </ul>

        <h2>Service providers &amp; sub-processors</h2>
        <p>We share data only as needed to operate the Service, with providers such as:
        Google (Gemini AI for text and image generation), Cloudflare (website crawling and
        delivery), Meta Platforms (advertising and the Meta Pixel / Conversions API),
        DigitalOcean (hosting), and our image-generation provider. These providers process data
        on our behalf under their own terms.</p>

        <h2>Cookies &amp; advertising</h2>
        <p>We and our partners use cookies, pixels, and similar technologies — including the Meta
        Pixel — to understand how visitors arrive and convert, and to optimise and attribute our
        advertising. You can control cookies through your browser settings; you may also adjust
        ad personalisation in your Meta account settings.</p>

        <h2>Data retention</h2>
        <p>We retain information for as long as needed to provide the Service and for legitimate
        business or legal purposes, after which we delete or anonymise it.</p>

        <h2>Security</h2>
        <p>We use reasonable technical and organisational measures to protect your information. No
        method of transmission or storage is completely secure, so we cannot guarantee absolute
        security.</p>

        <h2>Your rights</h2>
        <p>Depending on your location, you may have rights to access, correct, export, or delete
        your personal information, and to object to or restrict certain processing. To exercise
        these rights, contact us at the address below.</p>

        <h2>Children</h2>
        <p>The Service is intended for businesses and is not directed to children under 16.</p>

        <h2>Changes</h2>
        <p>We may update this policy from time to time. Material changes will be posted on this
        page with a new "Last updated" date.</p>

        <h2>Contact</h2>
        <p>Questions about this policy or your data? Email
        <a href="mailto:hello@layout.ai">hello@layout.ai</a>.</p>
    </div>

    <div class="mt-10 text-sm">
        <a href="{{ route('terms') }}" class="text-primary font-medium">Terms of Service</a>
        <span class="text-muted mx-2">·</span>
        <a href="{{ route('create.index') }}" class="text-primary font-medium">Back to Layout.ai</a>
    </div>
</div>
@endsection
