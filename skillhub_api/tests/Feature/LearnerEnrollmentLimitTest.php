<?php

namespace Tests\Feature;

use App\Models\Enrollment;
use App\Models\Formation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LearnerEnrollmentLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_learner_cannot_enroll_in_more_than_five_formations(): void
    {
        $learner = User::factory()->create([
            'role' => 'apprenant',
        ]);

        $token = $learner->createToken('phpunit')->plainTextToken;

        $formations = [];
        for ($i = 0; $i < 6; $i++) {
            $formations[] = Formation::create([
                'title' => 'Formation '.($i + 1),
                'description' => 'Description '.($i + 1),
                'duration' => '10h',
                'level' => 'beginner',
            ]);
        }

        foreach (array_slice($formations, 0, 5) as $formation) {
            Enrollment::create([
                'user_id' => $learner->id,
                'formation_id' => $formation->id,
                'progress' => 0,
                'enrolled_at' => now(),
            ]);
        }

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/learner/enrollments', [
                'formation_id' => $formations[5]->id,
            ]);

        $response
            ->assertStatus(400)
            ->assertJsonPath(
                'message',
                'Vous ne pouvez pas etre inscrit a plus de 5 formations simultanement'
            );

        $this->assertSame(5, Enrollment::where('user_id', $learner->id)->count());
    }

    public function test_learner_can_enroll_when_below_the_limit(): void
    {
        $learner = User::factory()->create([
            'role' => 'apprenant',
        ]);

        $token = $learner->createToken('phpunit')->plainTextToken;

        $formations = [];
        for ($i = 0; $i < 5; $i++) {
            $formations[] = Formation::create([
                'title' => 'Formation '.($i + 1),
                'description' => 'Description '.($i + 1),
                'duration' => '10h',
                'level' => 'beginner',
            ]);
        }

        foreach (array_slice($formations, 0, 4) as $formation) {
            Enrollment::create([
                'user_id' => $learner->id,
                'formation_id' => $formation->id,
                'progress' => 0,
                'enrolled_at' => now(),
            ]);
        }

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/learner/enrollments', [
                'formation_id' => $formations[4]->id,
            ]);

        $response->assertStatus(201);
        $this->assertSame(5, Enrollment::where('user_id', $learner->id)->count());
    }
}
