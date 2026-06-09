<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'gemini' => [
        'api_key'        => env('GEMINI_API_KEY'),
        'model'          => env('GEMINI_MODEL', 'gemini-2.5-flash'),
        // Concepts call (30 ads in one response) — heavier, uses 3.5-flash.
        'combined_model' => env('GEMINI_COMBINED_MODEL', 'gemini-3.5-flash'),
        // Brand summary — 7 lean fields, runs on the lighter/faster 2.5-flash.
        'brand_model'    => env('GEMINI_BRAND_MODEL', 'gemini-2.5-flash'),
        'base'           => env('GEMINI_BASE', 'https://generativelanguage.googleapis.com/v1beta'),
    ],

    'cloudflare' => [
        'account_id' => env('CLOUDFLARE_ACCOUNT_ID'),
        'api_token'  => env('CLOUDFLARE_API_TOKEN'),
        'endpoint'   => env('CLOUDFLARE_CRAWL_ENDPOINT'),
        // Zone-scoped token used for CDN cache purges (separate from the
        // account-scoped browser-rendering crawl token above).
        'dns_token'  => env('CLOUDFLARE_DNS_TOKEN'),
        'zone_id'    => env('CLOUDFLARE_ZONE_ID'),
    ],

    'runmyprint' => [
        'endpoint' => env('RUNMYPRINT_ENDPOINT', 'https://www.runmyprint.com/test/image2.php'),
    ],

    'renderer' => [
        'url'          => env('RENDERER_URL', 'http://renderer:3000'),
        'internal_url' => env('INTERNAL_PUBLIC_URL', 'http://nginx'),
    ],

    // Meta (Facebook/Instagram) — promote layout.ai. Marketing API for ad
    // delivery + Pixel/Conversions API for signup-conversion tracking.
    'meta' => [
        'app_id'         => env('META_APP_ID'),
        'app_secret'     => env('META_APP_SECRET'),
        'token'          => env('META_SYSTEM_USER_TOKEN'),   // long-lived system-user token
        'ad_account_id'  => env('META_AD_ACCOUNT_ID'),        // act_...
        'page_id'        => env('META_PAGE_ID'),
        'ig_account_id'  => env('META_INSTAGRAM_ACCOUNT_ID'), // optional
        'pixel_id'       => env('META_PIXEL_ID'),
        'capi_token'     => env('META_CAPI_TOKEN'),           // optional; falls back to system-user token
        'graph_version'  => env('META_GRAPH_VERSION', 'v21.0'),
    ],

    // Leadmaker (campaigns.leadmaker.ai) — auto-create + daily-sync an
    // acquisition campaign for every onboarded customer. The API key authorizes
    // both the create POST and the daily status GET.
    'leadmaker' => [
        'base' => env('LEADMAKER_BASE_URL', 'https://campaigns.leadmaker.ai'),
        'key'  => env('LEADMAKER_API_KEY'),
    ],

    // Support chat. Provider switch decides which widget the
    // <x-support-chat /> Blade component renders.
    //   - 'inapp'  : (default) self-hosted bubble + form, no external cost
    //   - 'zendesk': Zendesk Web Widget (paid after trial)
    //   - 'tawk'   : Tawk.to widget (free)
    'support' => [
        'provider'          => env('SUPPORT_PROVIDER', 'inapp'),
        'zendesk_key'       => env('SUPPORT_ZENDESK_KEY'),
        'tawk_property_id'  => env('SUPPORT_TAWK_PROPERTY_ID'),
        'tawk_widget_id'    => env('SUPPORT_TAWK_WIDGET_ID', 'default'),
        // Every incoming chat message is forwarded to this address (queued).
        // Falls back to MAIL_FROM_ADDRESS if unset. Default is hello@layout.ai
        // for the env example; the real recipient is set per-deploy.
        'notify_email'      => env('SUPPORT_NOTIFY_EMAIL', 'iberescu@gmail.com'),
    ],

];
