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

    /*
    |--------------------------------------------------------------------------
    | ML Matcher Service (Semantic EPG Matching)
    |--------------------------------------------------------------------------
    |
    | AI-powered channel name matching using sentence-transformers.
    | Runs as a Python microservice inside the container on port 5599.
    | Used as fallback when Levenshtein/cosine matching fails.
    |
    */
    'ml_matcher' => [
        'enabled' => env('ML_MATCHER_ENABLED', true),
        'url' => env('ML_MATCHER_URL', 'http://127.0.0.1:5599'),
    ],

];
