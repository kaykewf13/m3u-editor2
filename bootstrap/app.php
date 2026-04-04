<?php

use App\Http\Middleware\AutoLoginMiddleware;
use App\Http\Middleware\ProxyRateLimitMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware
            ->use([
                AutoLoginMiddleware::class,
            ])
            ->alias([
                'proxy.throttle' => ProxyRateLimitMiddleware::class,
            ])
            ->redirectGuestsTo('login')
            ->trustProxies(at: ['*'])
            ->preventRequestForgery(except: [
                'webhook/test',
                'channel',
                'channel/*',
                'group',
                'group/*',
                'player_api.php',
                'get.php',
            ])
            ->throttleWithRedis();
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
