<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrackLastActivityTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_api_call_refreshes_last_activity_at(): void
    {
        $learner = User::factory()->create([
            'role' => 'apprenant',
            'last_activity_at' => now()->subDays(40),
        ]);

        $token = $learner->createToken('phpunit')->plainTextToken;

        $this->freezeTime();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/me')
            ->assertOk();

        $learner->refresh();

        $this->assertNotNull($learner->last_activity_at);
        $this->assertSame(
            now()->toDateTimeString(),
            $learner->last_activity_at->toDateTimeString()
        );
    }

    public function test_last_activity_at_is_set_on_a_learner_who_never_had_one(): void
    {
        $learner = User::factory()->create([
            'role' => 'apprenant',
            'last_activity_at' => null,
        ]);

        $token = $learner->createToken('phpunit')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/me')
            ->assertOk();

        $this->assertNotNull($learner->fresh()->last_activity_at);
    }

    public function test_unauthenticated_api_call_is_left_untouched(): void
    {
        $learner = User::factory()->create([
            'role' => 'apprenant',
            'last_activity_at' => null,
        ]);

        $this->getJson('/api/me')->assertUnauthorized();

        $this->assertNull($learner->fresh()->last_activity_at);
    }
}
