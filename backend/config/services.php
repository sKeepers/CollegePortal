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
     * Вход через Telegram (`AUTH-003`). Обоих значений нет — способ входа просто не
     * подключается, и портал работает как раньше: список провайдеров пуст, кнопки нет.
     *
     * `?: null`, а не второй аргумент `env()`: пустое значение в `.env` это пустая
     * строка, а не «не задано», и с `env('X', null)` ключ считался бы заданным.
     */
    'telegram' => [
        'bot_username' => env('TELEGRAM_LOGIN_BOT_USERNAME') ?: null,
        'bot_token' => env('TELEGRAM_LOGIN_BOT_TOKEN') ?: null,
    ],

];
