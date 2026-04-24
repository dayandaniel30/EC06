<?php

namespace App\Http\Middleware;

use App\Services\SsoClient;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SsoAuthenticate
{
    public function __construct(private readonly SsoClient $ssoClient)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $header = (string) $request->header('Authorization', '');

        if (!str_starts_with($header, 'Bearer ')) {
            return new JsonResponse([
                'message' => 'Token SSO manquant. Authentifiez-vous via POST /api/sso/login.',
            ], 401);
        }

        $token = substr($header, 7);
        $claims = $this->ssoClient->validate($token);

        if ($claims === null) {
            return new JsonResponse([
                'message' => 'Token SSO invalide ou expire.',
            ], 401);
        }

        $request->attributes->set('sso_subject', $claims['subject'] ?? null);
        $request->attributes->set('sso_role', $claims['role'] ?? null);
        $request->attributes->set('sso_claims', $claims);
        $request->attributes->set('sso_token', $token);

        return $next($request);
    }
}
