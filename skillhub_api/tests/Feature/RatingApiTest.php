<?php

namespace Tests\Feature;

use App\Models\Enrollment;
use App\Models\Formation;
use App\Models\Rating;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RatingApiTest extends TestCase
{
    use RefreshDatabase;

    private function makeFormation(): Formation
    {
        return Formation::create([
            'title' => 'Formation a noter',
            'description' => 'Une formation pour les tests de notation',
            'duration' => '6h',
            'level' => 'beginner',
        ]);
    }

    private function makeLearner(): User
    {
        return User::factory()->create([
            'role' => 'apprenant',
        ]);
    }

    private function enroll(User $learner, Formation $formation): Enrollment
    {
        return Enrollment::create([
            'user_id' => $learner->id,
            'formation_id' => $formation->id,
            'progress' => 0,
            'enrolled_at' => now(),
        ]);
    }

    public function test_post_rating_without_token_returns_401(): void
    {
        $formation = $this->makeFormation();

        $response = $this->postJson('/api/formations/'.$formation->id.'/ratings', [
            'score' => 4,
            'comment' => 'Sympathique',
        ]);

        $response->assertStatus(401);
    }

    public function test_non_apprenant_cannot_rate(): void
    {
        $formateur = User::factory()->create(['role' => 'formateur']);
        $token = $formateur->createToken('phpunit')->plainTextToken;
        $formation = $this->makeFormation();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/formations/'.$formation->id.'/ratings', [
                'score' => 5,
            ]);

        $response
            ->assertStatus(403)
            ->assertJsonPath('message', 'Seuls les apprenants peuvent noter une formation');
    }

    public function test_apprenant_must_be_enrolled_to_rate(): void
    {
        $learner = $this->makeLearner();
        $token = $learner->createToken('phpunit')->plainTextToken;
        $formation = $this->makeFormation();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/formations/'.$formation->id.'/ratings', [
                'score' => 4,
                'comment' => 'Pas inscrit',
            ]);

        $response
            ->assertStatus(403)
            ->assertJsonPath('message', 'Vous devez etre inscrit a la formation pour la noter');

        $this->assertSame(0, Rating::count());
    }

    public function test_enrolled_apprenant_can_create_rating(): void
    {
        $learner = $this->makeLearner();
        $token = $learner->createToken('phpunit')->plainTextToken;
        $formation = $this->makeFormation();
        $this->enroll($learner, $formation);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/formations/'.$formation->id.'/ratings', [
                'score' => 5,
                'comment' => 'Excellente formation',
            ]);

        $response
            ->assertStatus(201)
            ->assertJsonPath('data.score', 5)
            ->assertJsonPath('data.comment', 'Excellente formation')
            ->assertJsonPath('data.formation_id', $formation->id)
            ->assertJsonPath('data.user_id', $learner->id);

        $this->assertDatabaseHas('ratings', [
            'user_id' => $learner->id,
            'formation_id' => $formation->id,
            'score' => 5,
        ]);
    }

    public function test_invalid_score_returns_422(): void
    {
        $learner = $this->makeLearner();
        $token = $learner->createToken('phpunit')->plainTextToken;
        $formation = $this->makeFormation();
        $this->enroll($learner, $formation);

        $tooHigh = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/formations/'.$formation->id.'/ratings', [
                'score' => 6,
            ]);
        $tooHigh->assertStatus(422)->assertJsonPath('errors.score.0', 'The score field must be between 1 and 5.');

        $tooLow = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/formations/'.$formation->id.'/ratings', [
                'score' => 0,
            ]);
        $tooLow->assertStatus(422);

        $missing = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/formations/'.$formation->id.'/ratings', []);
        $missing->assertStatus(422)->assertJsonPath('errors.score.0', 'The score field is required.');

        $this->assertSame(0, Rating::count());
    }

    public function test_second_rating_by_same_user_updates_existing(): void
    {
        $learner = $this->makeLearner();
        $token = $learner->createToken('phpunit')->plainTextToken;
        $formation = $this->makeFormation();
        $this->enroll($learner, $formation);

        $first = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/formations/'.$formation->id.'/ratings', [
                'score' => 3,
                'comment' => 'Pas mal',
            ]);
        $first->assertStatus(201);

        $second = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/formations/'.$formation->id.'/ratings', [
                'score' => 5,
                'comment' => 'Finalement super',
            ]);

        $second
            ->assertStatus(200)
            ->assertJsonPath('message', 'Avis mis a jour')
            ->assertJsonPath('data.score', 5)
            ->assertJsonPath('data.comment', 'Finalement super');

        $this->assertSame(1, Rating::where('user_id', $learner->id)->count());
    }

    public function test_summary_returns_average_and_count(): void
    {
        $formation = $this->makeFormation();

        $learners = User::factory()->count(3)->create(['role' => 'apprenant']);
        foreach ($learners as $learner) {
            $this->enroll($learner, $formation);
        }

        Rating::create([
            'user_id' => $learners[0]->id,
            'formation_id' => $formation->id,
            'score' => 5,
        ]);
        Rating::create([
            'user_id' => $learners[1]->id,
            'formation_id' => $formation->id,
            'score' => 3,
        ]);
        Rating::create([
            'user_id' => $learners[2]->id,
            'formation_id' => $formation->id,
            'score' => 4,
        ]);

        $reader = User::factory()->create(['role' => 'apprenant']);
        $token = $reader->createToken('phpunit')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/formations/'.$formation->id.'/ratings/summary');

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.formation_id', $formation->id)
            ->assertJsonPath('data.count', 3)
            ->assertJsonPath('data.average', fn ($average) => (float) $average === 4.0);
    }

    public function test_summary_with_no_ratings_returns_zero(): void
    {
        $formation = $this->makeFormation();
        $reader = User::factory()->create(['role' => 'apprenant']);
        $token = $reader->createToken('phpunit')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/formations/'.$formation->id.'/ratings/summary');

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.count', 0)
            ->assertJsonPath('data.average', fn ($average) => (float) $average === 0.0);
    }

    public function test_index_returns_list_of_ratings(): void
    {
        $formation = $this->makeFormation();

        $learner = $this->makeLearner();
        $this->enroll($learner, $formation);
        Rating::create([
            'user_id' => $learner->id,
            'formation_id' => $formation->id,
            'score' => 4,
            'comment' => 'Tres bien',
        ]);

        $reader = User::factory()->create(['role' => 'apprenant']);
        $token = $reader->createToken('phpunit')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/formations/'.$formation->id.'/ratings');

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.0.score', 4)
            ->assertJsonPath('data.0.comment', 'Tres bien')
            ->assertJsonPath('data.0.user_id', $learner->id);
    }

    public function test_apprenant_can_delete_own_rating(): void
    {
        $learner = $this->makeLearner();
        $token = $learner->createToken('phpunit')->plainTextToken;
        $formation = $this->makeFormation();
        $this->enroll($learner, $formation);

        Rating::create([
            'user_id' => $learner->id,
            'formation_id' => $formation->id,
            'score' => 2,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson('/api/formations/'.$formation->id.'/ratings');

        $response
            ->assertStatus(200)
            ->assertJsonPath('message', 'Avis supprime');

        $this->assertSame(0, Rating::where('user_id', $learner->id)->count());
    }

    public function test_delete_when_no_rating_returns_404(): void
    {
        $learner = $this->makeLearner();
        $token = $learner->createToken('phpunit')->plainTextToken;
        $formation = $this->makeFormation();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson('/api/formations/'.$formation->id.'/ratings');

        $response->assertStatus(404);
    }
}
