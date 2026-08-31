<?php

use App\Http\Middleware\AuthenticateApiToken;
use App\Http\Middleware\EnsureCsrfToken;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\ViewAsPerson;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'api.token' => AuthenticateApiToken::class,
            'api.csrf' => EnsureCsrfToken::class,
            'permission' => EnsurePermission::class,
            'view.as' => ViewAsPerson::class,
        ]);

        // Без этого за обратным прокси $request->ip() возвращает адрес прокси, а
        // не человека: на DEV журнал аудита писал 172.18.0.4 для трёх запросов из
        // четырёх, и ограничитель входа считал попытки всего портала как с одного
        // адреса. На PROD nginx ходит в backend через fastcgi и адрес пока верный —
        // до дня, когда перед ним появится ещё один прокси.
        //
        // Доверяем не всем: заголовку X-Forwarded-For можно верить только от того,
        // кто его ставит. По умолчанию — сеть Docker Compose, где и живёт прокси.
        // Локальную сеть колледжа сюда включать нельзя: её клиенты ходят напрямую
        // и смогли бы подменить свой адрес в журнале аудита.
        $middleware->trustProxies(
            at: array_values(array_filter(array_map(
                'trim',
                explode(',', (string) env('TRUSTED_PROXIES', '127.0.0.1,172.16.0.0/12')),
            ))),
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(function (Request $request, Throwable $e): bool {
            return $request->is('api/*') || $request->expectsJson();
        });
    })->create();
