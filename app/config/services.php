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
        'combined_model' => env('GEMINI_COMBINED_MODEL', 'gemini-3.5-flash'),
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

    // Creative scoring (TRIBE v2 by default, runs on a hosted GPU).
    // provider: 'replicate' | 'mock'   ('mock' = deterministic offline score)
    'creative_scoring' => [
        'provider'             => env('CREATIVE_SCORING_PROVIDER', 'mock'),
        'replicate_token'      => env('REPLICATE_API_TOKEN'),
        // Two ways to point at a model on Replicate:
        //   1. REPLICATE_TRIBE_MODEL=owner/name:version_hash → direct prediction
        //      on the model (one replica, autoscaled by Replicate's allocator).
        //   2. REPLICATE_TRIBE_DEPLOYMENT=owner/deployment → uses our deployment,
        //      which has explicit max_instances control + faster autoscale-up.
        // Deployment takes precedence when set.
        'replicate_model'      => env('REPLICATE_TRIBE_MODEL'),
        'replicate_deployment' => env('REPLICATE_TRIBE_DEPLOYMENT'),
    ],

];
