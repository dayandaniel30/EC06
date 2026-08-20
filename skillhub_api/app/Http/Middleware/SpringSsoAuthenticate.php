<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\SsoClient;
use App\Services\SsoUserProvisioner;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Delegue l'authentification de la requete au microservice SSO Spring Boot.
 *
 * Laravel ne verifie jamais de mot de passe : il extrait le Bearer token,
 * le fait valider par `GET /auth/validate` cote Spring (signature, issuer,
 * audience, expiration), puis rattache l'utilisateur local correspondant a la
 * requete via `Auth::setUser()`. Cette liaison est volontairement limitee a la
 * duree de la requete : aucune session n'est ouverte, l'etat reste stateless.
 *
 * Consequence importante : une fois `Auth::setUser()` appele, `$request->user()`
 * repond, ce qui permet aux middlewares en aval — notamment
 * {@see TrackLastActivity} — et aux controleurs metier de fonctionner a
 * l'identique qu'ils soient derriere `auth:sanctum` ou derriere le SSO.
 */
class SpringSsoAuthenticate
{
    public function __construct(
        private readonly SsoClient $ssoClient,
        private readonly SsoUserProvisioner $provisioner,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $this->bearerToken($request);

        if ($token === null) {
            return $this->deny('Token SSO manquant. Authentifiez-vous via POST /api/sso/login.', 401);
        }

        $claims = $this->ssoClient->validate($token);

        if ($claims === null) {
            return $this->deny('Token SSO invalide ou expire.', 401);
        }

        $user = $this->provisioner->fromClaims($claims);

        if ($user === null) {
            return $this->deny('Token SSO exploitable mais sans identite utilisateur.', 401);
        }

        // Regle metier Q1 : un compte purge pour inactivite garde un JWT
        // techniquement valide jusqu'a son expiration. Le refus se fait donc ici,
        // et non cote SSO, car c'est Laravel qui porte le statut du compte.
        if (! $user->isActive()) {
            return $this->deny(
                'Compte desactive pour inactivite prolongee. Contactez un administrateur pour le reactiver.',
                403,
            );
        }

        $this->bindUser($request, $user, $claims, $token);

        return $next($request);
    }

    /**
     * Extrait le JWT de l'en-tete `Authorization`.
     */
    private function bearerToken(Request $request): ?string
    {
        $header = (string) $request->header('Authorization', '');

        if (! str_starts_with($header, 'Bearer ')) {
            return null;
        }

        $token = trim(substr($header, 7));

        return $token === '' ? null : $token;
    }

    /**
     * Rattache l'utilisateur et les claims a la requete courante.
     *
     * Les attributs `sso_*` sont conserves pour les consommateurs qui lisent
     * les claims brutes plutot que l'utilisateur Eloquent.
     *
     * @param  array<string, mixed>  $claims
     */
    private function bindUser(Request $request, User $user, array $claims, string $token): void
    {
        Auth::setUser($user);
        $request->setUserResolver(static fn (): User => $user);

        $request->attributes->set('sso_subject', $claims['subject'] ?? null);
        $request->attributes->set('sso_role', $claims['role'] ?? null);
        $request->attributes->set('sso_claims', $claims);
        $request->attributes->set('sso_token', $token);
    }

    /**
     * Reponse d'echec normalisee au format JSON.
     */
    private function deny(string $message, int $status): JsonResponse
    {
        return new JsonResponse(['message' => $message], $status);
    }
}
