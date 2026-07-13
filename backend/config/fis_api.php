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
    'schema_version' => env('FIS_API_SCHEMA_VERSION', 'pending-official-spec'),
    'spec_manifest_path' => env('FIS_API_SPEC_MANIFEST_PATH', storage_path('app/private/fis-specs/4.9/manifest.json')),
    'xsd_path' => env('FIS_API_XSD_PATH'),
    'wsdl_path' => env('FIS_API_WSDL_PATH'),
];
