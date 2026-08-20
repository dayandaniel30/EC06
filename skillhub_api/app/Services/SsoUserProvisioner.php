<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Fait le pont entre l'identite portee par un JWT du SSO Spring Boot
 * et l'utilisateur local Laravel.
 *
 * Laravel ne detient pas les mots de passe primaires : ils vivent uniquement
 * dans `sso_db`. Cette classe garantit qu'un enregistrement local existe malgre
 * tout, car le domaine metier (formations, inscriptions, notes) s'appuie sur des
 * cles etrangeres vers `users.id`, et parce que la regle d'inactivite (Q1) a
 * besoin d'une ligne ou stocker `last_activity_at`.
 *
 * Le compte local cree ne contient aucun secret exploitable : le champ
 * `password` recoit une valeur aleatoire jamais communiquee, la seule voie
 * d'authentification restant le SSO.
 */
class SsoUserProvisioner
{
    /**
     * Correspondance entre les roles du SSO (majuscules) et ceux de Laravel.
     *
     * @var array<string, string>
     */
    private const ROLE_MAP = [
        'APPRENANT' => 'apprenant',
        'FORMATEUR' => 'formateur',
        'ADMIN' => 'admin',
    ];

    /**
     * Resout l'utilisateur local correspondant aux claims du JWT.
     *
     * @param  array<string, mixed>  $claims  reponse de `GET /auth/validate`
     * @return User|null l'utilisateur local, ou null si les claims sont inexploitables
     */
    public function fromClaims(array $claims): ?User
    {
        $subject = isset($claims['subject']) ? trim((string) $claims['subject']) : '';

        if ($subject === '') {
            return null;
        }

        $role = $this->mapRole($claims['role'] ?? null);

        /** @var User|null $user */
        $user = User::query()->where('email', $subject)->first();

        if ($user !== null) {
            $this->syncRole($user, $role);

            return $user;
        }

        return $this->provision($subject, $role);
    }

    /**
     * Cree l'enregistrement local miroir d'un compte SSO inconnu de Laravel.
     */
    private function provision(string $subject, string $role): User
    {
        /** @var User $user */
        $user = User::query()->create([
            'name' => Str::before($subject, '@') ?: $subject,
            'email' => $subject,
            // Mot de passe inutilisable : l'authentification passe exclusivement par le SSO.
            'password' => Str::random(48),
            'role' => $role,
            'last_activity_at' => now(),
            'is_active' => true,
        ]);

        Log::info('Utilisateur provisionne depuis le SSO', [
            'user_id' => (int) $user->getKey(),
            'email' => $subject,
            'role' => $role,
        ]);

        return $user;
    }

    /**
     * Aligne le role local sur celui porte par le JWT.
     *
     * Le SSO est la source de verite pour les roles : si un formateur est
     * retrograde cote SSO, Laravel doit le refleter des la requete suivante.
     */
    private function syncRole(User $user, string $role): void
    {
        if ((string) $user->role === $role) {
            return;
        }

        $user->forceFill(['role' => $role])->save();

        Log::info('Role synchronise depuis le SSO', [
            'user_id' => (int) $user->getKey(),
            'role' => $role,
        ]);
    }

    /**
     * Traduit un role SSO en role Laravel, avec repli sur `apprenant`.
     */
    private function mapRole(mixed $ssoRole): string
    {
        $normalized = strtoupper(trim((string) $ssoRole));

        return self::ROLE_MAP[$normalized] ?? 'apprenant';
    }
}
