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
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | MarTech dashboard — internal API-key gate
    |--------------------------------------------------------------------------
    */
    'dashboard' => [
        'api_key' => env('DASHBOARD_API_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Google service account (used for GA4 BigQuery + Search Console)
    |--------------------------------------------------------------------------
    */
    'google' => [
        'service_account_path' => env('GOOGLE_APPLICATION_CREDENTIALS', storage_path('credentials/ga4-sa.json')),
        'ga4_project_id' => env('GA4_BIGQUERY_PROJECT_ID', 'ga4-bitunix-bigquery'),
        'ga4_dataset' => env('GA4_BIGQUERY_DATASET', 'analytics_476333519'),
        'ga4_property_id' => env('GA4_PROPERTY_ID', '476333519'),
        'gsc_site_url' => env('GSC_SITE_URL', 'https://www.bitunix.com/'),
        // Brand-name terms used to split queries into branded vs non-branded.
        // Comma-separated, case-insensitive substring match in SQL.
        'gsc_brand_terms' => array_filter(
            array_map('trim', explode(',', (string) env('GSC_BRAND_TERMS', 'bitunix,битуникс,bitunex,bitunixs'))),
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Bitunix Partner Portal (private API — JWT rotated manually)
    |--------------------------------------------------------------------------
    */
    'bitunix' => [
        'base_url' => env('BITUNIX_PARTNER_BASE_URL', 'https://partners.bitunix.com'),
        'timeout' => (int) env('BITUNIX_PARTNER_TIMEOUT', 30),
    ],

];
