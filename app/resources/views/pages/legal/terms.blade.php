@extends('layouts.marketing')

@section('content')
<div class="max-w-3xl mx-auto px-6 py-16">
    <h1 class="text-4xl font-extrabold tracking-tight text-ink">Terms of Service</h1>
    <p class="mt-2 text-sm text-muted">Last updated: June 8, 2026</p>

    <div class="prose prose-slate mt-8 max-w-none">
        <p>These Terms of Service ("Terms") govern your use of <strong>Layout.ai</strong>
        (the "Service") at <a href="https://layout.ai">layout.ai</a>. By using the Service you
        agree to these Terms.</p>

        <h2>The Service</h2>
        <p>Layout.ai scans a website you provide, learns the brand, and automatically generates
        and evaluates display and social ad creatives. Output is produced by automated systems
        and may require your review before use.</p>

        <h2>Accounts</h2>
        <p>You must provide accurate information and are responsible for activity under your
        account. You must have authority to submit any website, brand assets, or content you
        provide.</p>

        <h2>Promotional credit</h2>
        <p>Any advertising credit or promotional offer (including a "$500 ad credit") is provided
        at our discretion, may require eligibility conditions, has no cash value, is
        non-transferable, and may be modified or withdrawn at any time. Specific terms are shown
        at the time of the offer.</p>

        <h2>Acceptable use</h2>
        <p>You agree not to use the Service to create content that is unlawful, infringing,
        deceptive, or that violates the advertising policies of any platform on which the ads are
        run. You will not misuse, reverse-engineer, or disrupt the Service.</p>

        <h2>Your content &amp; generated ads</h2>
        <p>You retain ownership of the brand assets and inputs you provide. You grant us a license
        to process them to operate the Service. Subject to your compliance with these Terms, you
        may use the ad creatives generated for your business. You are responsible for ensuring
        generated ads are accurate and compliant before publishing them.</p>

        <h2>Third-party platforms</h2>
        <p>The Service integrates with third parties (for example, advertising networks and AI
        providers). Your use of those platforms is subject to their terms, and we are not
        responsible for them.</p>

        <h2>Disclaimers</h2>
        <p>The Service is provided "as is" without warranties of any kind. We do not guarantee any
        particular advertising result, performance, or conversion outcome.</p>

        <h2>Limitation of liability</h2>
        <p>To the maximum extent permitted by law, Layout.ai will not be liable for indirect,
        incidental, or consequential damages, or for lost profits or ad spend, arising from your
        use of the Service.</p>

        <h2>Termination</h2>
        <p>We may suspend or terminate access for violation of these Terms or to protect the
        Service. You may stop using the Service at any time.</p>

        <h2>Changes</h2>
        <p>We may update these Terms; material changes will be posted here with a new date.
        Continued use after changes means you accept them.</p>

        <h2>Contact</h2>
        <p>Questions? Email <a href="mailto:hello@layout.ai">hello@layout.ai</a>.</p>
    </div>

    <div class="mt-10 text-sm">
        <a href="{{ route('privacy') }}" class="text-primary font-medium">Privacy Policy</a>
        <span class="text-muted mx-2">·</span>
        <a href="{{ route('create.index') }}" class="text-primary font-medium">Back to Layout.ai</a>
    </div>
</div>
@endsection
