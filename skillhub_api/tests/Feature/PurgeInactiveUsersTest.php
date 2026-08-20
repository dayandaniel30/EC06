<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Couvre la regle metier Q1 : desactivation des comptes inactifs (6 mois).
 */
class PurgeInactiveUsersTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_deactivates_a_user_inactive_for_more_than_six_months(): void
    {
        $user = User::factory()->create([
            'role' => 'apprenant',
            'last_activity_at' => now()->subDays(200),
        ]);

        $this->artisan('users:purge-inactive')->assertSuccessful();

        $user->refresh();

        $this->assertFalse($user->is_active);
        $this->assertNotNull($user->deactivated_at);
        $this->assertSame(User::DEACTIVATION_REASON_INACTIVITY, $user->deactivation_reason);
    }

    public function test_it_revokes_the_access_tokens_of_a_purged_user(): void
    {
        $user = User::factory()->create([
            'role' => 'apprenant',
            'last_activity_at' => now()->subDays(200),
        ]);

        $user->createToken('phpunit');

        $this->assertSame(1, $user->tokens()->count());

        $this->artisan('users:purge-inactive')->assertSuccessful();

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_it_leaves_a_recently_active_user_untouched(): void
    {
        $user = User::factory()->create([
            'role' => 'apprenant',
            'last_activity_at' => now()->subDays(10),
        ]);

        $this->artisan('users:purge-inactive')->assertSuccessful();

        $user->refresh();

        $this->assertTrue($user->is_active);
        $this->assertNull($user->deactivated_at);
    }

    public function test_it_never_purges_a_user_without_recorded_activity(): void
    {
        $user = User::factory()->create([
            'role' => 'apprenant',
            'last_activity_at' => null,
        ]);

        $this->artisan('users:purge-inactive')->assertSuccessful();

        $this->assertTrue($user->fresh()->is_active);
    }

    public function test_dry_run_reports_without_modifying_anything(): void
    {
        $user = User::factory()->create([
            'role' => 'apprenant',
            'last_activity_at' => now()->subDays(200),
        ]);

        $user->createToken('phpunit');

        $this->artisan('users:purge-inactive', ['--dry-run' => true])->assertSuccessful();

        $user->refresh();

        $this->assertTrue($user->is_active);
        $this->assertNull($user->deactivated_at);
        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_the_days_threshold_can_be_overridden(): void
    {
        $user = User::factory()->create([
            'role' => 'apprenant',
            'last_activity_at' => now()->subDays(45),
        ]);

        // 45 jours d'inactivite : sous le seuil par defaut de 180 jours...
        $this->artisan('users:purge-inactive')->assertSuccessful();
        $this->assertTrue($user->fresh()->is_active);

        // ...mais au-dela d'un seuil abaisse a 30 jours.
        $this->artisan('users:purge-inactive', ['--days' => 30])->assertSuccessful();
        $this->assertFalse($user->fresh()->is_active);
    }

    public function test_it_rejects_an_invalid_days_option(): void
    {
        $this->artisan('users:purge-inactive', ['--days' => 0])->assertFailed();
    }

    public function test_an_already_deactivated_user_is_not_processed_twice(): void
    {
        $deactivatedAt = now()->subDays(5);

        $user = User::factory()->create([
            'role' => 'apprenant',
            'last_activity_at' => now()->subDays(200),
            'is_active' => false,
            'deactivated_at' => $deactivatedAt,
            'deactivation_reason' => User::DEACTIVATION_REASON_INACTIVITY,
        ]);

        $this->artisan('users:purge-inactive')->assertSuccessful();

        $this->assertSame(
            $deactivatedAt->toDateTimeString(),
            $user->fresh()->deactivated_at->toDateTimeString(),
        );
    }
}
