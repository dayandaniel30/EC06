<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\SsoClient;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
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

        $subject = (string) ($claims['subject'] ?? '');
        $role = strtolower((string) ($claims['role'] ?? 'apprenant'));

        $user = null;
        if ($subject !== '') {
            $user = User::firstOrNew(['email' => $subject]);
            if (!$user->exists) {
                $user->password = Hash::make(Str::random(40));
                $user->name = strstr($subject, '@', true) ?: $subject;
            }
            $user->role = $role;
            $user->save();
        }

        $request->attributes->set('sso_subject', $subject);
        $request->attributes->set('sso_role', $role);
        $request->attributes->set('sso_claims', $claims);
        $request->attributes->set('sso_token', $token);

        if ($user) {
            $request->setUserResolver(fn () => $user);
        }

        return $next($request);
    }
}
