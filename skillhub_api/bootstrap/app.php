<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->alias([
            'sso' => \App\Http\Middleware\SsoAuthenticate::class,
            'activity' => \App\Http\Middleware\TrackLastActivity::class,
        ]);

        // Trace la derniere activite sur chaque appel API authentifie.
        $middleware->appendToGroup('api', \App\Http\Middleware\TrackLastActivity::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
