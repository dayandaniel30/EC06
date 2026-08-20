<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Couvre la delegation d'authentification au microservice Spring Boot SSO.
 *
 * Le service distant est simule via `Http::fake()` : ces tests verifient le
 * contrat cote Laravel (rejet, provisioning, synchronisation, statut du compte),
 * pas l'implementation du SSO lui-meme.
 */
class SpringSsoAuthenticateTest extends TestCase
{
    use RefreshDatabase;

    private const VALIDATE_URL = 'http://localhost:8080/auth/validate';

    /**
     * Simule une reponse de validation positive du SSO.
     */
    private function fakeValidToken(string $subject, string $role = 'APPRENANT'): void
    {
        Http::fake([
            self::VALIDATE_URL => Http::response([
                'valid' => true,
                'subject' => $subject,
                'role' => $role,
                'issuer' => 'skillhub-sso',
                'audience' => ['skillhub-laravel'],
                'expiresAt' => now()->addHour()->toIso8601String(),
            ], 200),
        ]);
    }

    public function test_a_request_without_a_bearer_token_is_rejected(): void
    {
        Http::fake();

        $this->getJson('/api/sso/me')->assertUnauthorized();

        Http::assertNothingSent();
    }

    public function test_a_token_rejected_by_the_sso_is_rejected_by_laravel(): void
    {
        Http::fake([
            self::VALIDATE_URL => Http::response(['valid' => false], 401),
        ]);

        $this->withHeader('Authorization', 'Bearer forged.jwt.value')
            ->getJson('/api/sso/me')
            ->assertUnauthorized();
    }

    public function test_an_unreachable_sso_does_not_grant_access(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('SSO down'));

        $this->withHeader('Authorization', 'Bearer any.jwt.value')
            ->getJson('/api/sso/me')
            ->assertUnauthorized();
    }

    public function test_a_valid_token_provisions_the_local_user_and_grants_access(): void
    {
        $this->fakeValidToken('apprenant@skillhub.test');

        $this->withHeader('Authorization', 'Bearer valid.jwt.value')
            ->getJson('/api/sso/me')
            ->assertOk()
            ->assertJsonPath('subject', 'apprenant@skillhub.test')
            ->assertJsonPath('user.email', 'apprenant@skillhub.test');

        $this->assertDatabaseHas('users', [
            'email' => 'apprenant@skillhub.test',
            'role' => 'apprenant',
        ]);
    }

    public function test_an_existing_local_user_is_reused_and_not_duplicated(): void
    {
        $existing = User::factory()->create([
            'email' => 'formateur@skillhub.test',
            'role' => 'formateur',
        ]);

        $this->fakeValidToken('formateur@skillhub.test', 'FORMATEUR');

        $this->withHeader('Authorization', 'Bearer valid.jwt.value')
            ->getJson('/api/sso/me')
            ->assertOk()
            ->assertJsonPath('user.id', $existing->id);

        $this->assertSame(1, User::query()->where('email', 'formateur@skillhub.test')->count());
    }

    public function test_the_local_role_is_synchronised_from_the_sso_claims(): void
    {
        $user = User::factory()->create([
            'email' => 'promu@skillhub.test',
            'role' => 'apprenant',
        ]);

        $this->fakeValidToken('promu@skillhub.test', 'FORMATEUR');

        $this->withHeader('Authorization', 'Bearer valid.jwt.value')
            ->getJson('/api/sso/me')
            ->assertOk();

        $this->assertSame('formateur', $user->fresh()->role);
    }

    public function test_a_deactivated_account_is_refused_even_with_a_valid_token(): void
    {
        User::factory()->create([
            'email' => 'inactif@skillhub.test',
            'role' => 'apprenant',
            'is_active' => false,
            'deactivated_at' => now()->subDay(),
            'deactivation_reason' => User::DEACTIVATION_REASON_INACTIVITY,
        ]);

        $this->fakeValidToken('inactif@skillhub.test');

        $this->withHeader('Authorization', 'Bearer valid.jwt.value')
            ->getJson('/api/sso/me')
            ->assertForbidden();
    }

    public function test_an_sso_authenticated_call_refreshes_last_activity_at(): void
    {
        $user = User::factory()->create([
            'email' => 'suivi@skillhub.test',
            'role' => 'apprenant',
            'last_activity_at' => now()->subDays(200),
        ]);

        $this->fakeValidToken('suivi@skillhub.test');
        $this->freezeTime();

        $this->withHeader('Authorization', 'Bearer valid.jwt.value')
            ->getJson('/api/sso/me')
            ->assertOk();

        $this->assertSame(
            now()->toDateTimeString(),
            $user->fresh()->last_activity_at->toDateTimeString(),
        );
    }
}
