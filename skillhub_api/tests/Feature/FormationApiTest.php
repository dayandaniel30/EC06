<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FormationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_post_formations_without_token_returns_401(): void
    {
        $response = $this->postJson('/api/formations', [
            'title' => 'Formation API',
            'description' => 'Sans token',
            'price' => 99.9,
            'duration' => 6,
            'level' => 'beginner',
        ]);

        $response->assertStatus(401);
    }

    public function test_post_formations_with_authenticated_user_returns_201(): void
    {
        $user = User::factory()->create([
            'role' => 'formateur',
        ]);

        $token = $user->createToken('phpunit')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/formations', [
                'title' => 'Formation Laravel',
                'description' => 'Creation valide',
                'price' => 120.5,
                'duration' => 8,
                'level' => 'intermediate',
            ]);

        $response
            ->assertStatus(201)
            ->assertJsonPath('data.title', 'Formation Laravel')
            ->assertJsonPath('data.user_id', $user->id);
    }
}
