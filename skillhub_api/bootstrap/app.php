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
        // Pas de statefulApi() : cette API est purement porteuse de jetons
        // (jetons personnels Sanctum et JWT du SSO Spring Boot), aucun
        // controleur n'ouvre de session. Or `sanctum.stateful` liste
        // `localhost:3000` par defaut : le middleware aurait bascule les appels
        // du dashboard en mode session, ou chaque POST est refuse par la
        // protection CSRF (419 « CSRF token mismatch ») faute de cookie XSRF.
        $middleware->alias([
            // Delegue l'authentification au microservice Spring Boot SSO.
            'sso' => \App\Http\Middleware\SpringSsoAuthenticate::class,
            'activity' => \App\Http\Middleware\TrackLastActivity::class,
        ]);

        // Trace la derniere activite sur chaque appel API authentifie,
        // que la session vienne de Sanctum ou du SSO Spring Boot.
        $middleware->appendToGroup('api', \App\Http\Middleware\TrackLastActivity::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
