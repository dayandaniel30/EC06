<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enregistre la date de derniere activite de l'utilisateur authentifie.
 *
 * La mise a jour est faite apres $next() : les middlewares de route
 * (auth:sanctum, sso) n'ont resolu l'utilisateur qu'a ce moment la.
 */
class TrackLastActivity
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $user = $request->user();

        if ($user instanceof User && $user->exists) {
            $user->forceFill(['last_activity_at' => now()])->save();
        }

        return $response;
    }
}
