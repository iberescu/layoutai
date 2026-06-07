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
    ],

    'runmyprint' => [
        'endpoint' => env('RUNMYPRINT_ENDPOINT', 'https://www.runmyprint.com/test/image2.php'),
    ],

    'renderer' => [
        'url'          => env('RENDERER_URL', 'http://renderer:3000'),
        'internal_url' => env('INTERNAL_PUBLIC_URL', 'http://nginx'),
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
