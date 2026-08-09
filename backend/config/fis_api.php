<?php

return [
    'enabled' => (bool) env('FIS_API_ENABLED', false),
    'mode' => env('FIS_API_MODE', 'test'),
    'allow_production_send' => (bool) env('FIS_API_ALLOW_PRODUCTION_SEND', false),
    'test_endpoint' => env('FIS_API_TEST_ENDPOINT', 'http://10.0.3.1:8383/api/import/importservice.svc'),
    'production_endpoint' => env('FIS_API_PRODUCTION_ENDPOINT', 'http://10.0.3.1:8080/api/import/importservice.svc'),
    'connect_timeout' => (int) env('FIS_API_CONNECT_TIMEOUT', 5),
    'request_timeout' => (int) env('FIS_API_REQUEST_TIMEOUT', 30),
    'transport' => env('FIS_API_TRANSPORT', 'soap'),
    'gateway_url' => env('FIS_GATEWAY_URL'),
    'gateway_enabled' => (bool) env('FIS_GATEWAY_ENABLED', false),
    'gateway_shared_secret' => env('FIS_GATEWAY_SHARED_SECRET'),
    'gateway_allowed_environment' => env('FIS_GATEWAY_ALLOWED_ENVIRONMENT', 'test'),
    'gateway_connect_timeout' => (int) env('FIS_GATEWAY_CONNECT_TIMEOUT', env('FIS_API_CONNECT_TIMEOUT', 5)),
    'gateway_request_timeout' => (int) env('FIS_GATEWAY_REQUEST_TIMEOUT', env('FIS_API_REQUEST_TIMEOUT', 30)),

    // Версия официальной спецификации сервиса автоматизированного взаимодействия.
    // 4.9 от 15.06.2026 — та, по которой собран файл в resources/fis/gia-priem.
    // `?:`, а не второй аргумент env(): в .env эти ключи давно объявлены
    // пустыми, а пустая строка — это не «не задано», и значение по умолчанию
    // до приложения не доходило.
    'schema_version' => env('FIS_API_SCHEMA_VERSION') ?: '4.9',
    'spec_manifest_path' => env('FIS_API_SPEC_MANIFEST_PATH') ?: resource_path('fis/gia-priem/4.9/manifest.json'),
    'xsd_path' => env('FIS_API_XSD_PATH') ?: resource_path('fis/gia-priem/4.9/import-package.xsd'),
    'wsdl_path' => env('FIS_API_WSDL_PATH'),

    // Значения справочников ФИС, которым в портале не соответствует элемент
    // справочника. Пусто по умолчанию: неверный ИД в официальном пакете — это
    // недостоверные сведения, поэтому лучше отказ сборки, чем догадка.
    // Действующие значения берутся методом получения элементов справочника
    // через шлюз и проставляются в .env.
    'dictionaries' => [
        // Справочник №5 «Пол».
        'gender' => [
            'male' => is_numeric(env('FIS_DICT_GENDER_MALE')) ? (int) env('FIS_DICT_GENDER_MALE') : null,
            'female' => is_numeric(env('FIS_DICT_GENDER_FEMALE')) ? (int) env('FIS_DICT_GENDER_FEMALE') : null,
        ],
    ],
];
