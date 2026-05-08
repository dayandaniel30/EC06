<?php

namespace Tests\Feature;

use App\Models\Enrollment;
use App\Models\Formation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrainerEnrolledLearnersTest extends TestCase
{
    use RefreshDatabase;

    private function makeFormation(?int $userId = null, string $title = 'Formation X'): Formation
    {
        $formation = Formation::create([
            'title' => $title,
            'description' => 'Description',
            'duration' => '8h',
            'level' => 'beginner',
        ]);
        if ($userId !== null) {
            $formation->user_id = $userId;
            $formation->save();
        }
        return $formation;
    }

    private function makeLearner(): User
    {
        return User::factory()->create(['role' => 'apprenant']);
    }

    private function enroll(User $learner, Formation $formation, int $progress = 0): Enrollment
    {
        return Enrollment::create([
            'user_id' => $learner->id,
            'formation_id' => $formation->id,
            'progress' => $progress,
            'enrolled_at' => now(),
        ]);
    }

    public function test_index_without_token_returns_401(): void
    {
        $response = $this->getJson('/api/formateur/enrollments');
        $response->assertStatus(401);
    }

    public function test_index_with_apprenant_role_returns_403(): void
    {
        $apprenant = User::factory()->create(['role' => 'apprenant']);
        $token = $apprenant->createToken('phpunit')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/formateur/enrollments');

        $response
            ->assertStatus(403)
            ->assertJsonPath('message', 'Action reservee aux formateurs');
    }

    public function test_index_returns_only_enrollments_in_owned_formations(): void
    {
        $trainer = User::factory()->create(['role' => 'formateur']);
        $otherTrainer = User::factory()->create(['role' => 'formateur']);
        $token = $trainer->createToken('phpunit')->plainTextToken;

        $myFormation = $this->makeFormation($trainer->id, 'Mon cours');
        $otherFormation = $this->makeFormation($otherTrainer->id, 'Cours autre formateur');

        $learnerA = $this->makeLearner();
        $learnerB = $this->makeLearner();
        $learnerC = $this->makeLearner();

        $this->enroll($learnerA, $myFormation, 25);
        $this->enroll($learnerB, $myFormation, 60);
        $this->enroll($learnerC, $otherFormation, 10);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/formateur/enrollments');

        $response
            ->assertStatus(200)
            ->assertJsonCount(2, 'data');

        $userIds = collect($response->json('data'))->pluck('user_id')->all();
        $this->assertContains($learnerA->id, $userIds);
        $this->assertContains($learnerB->id, $userIds);
        $this->assertNotContains($learnerC->id, $userIds);
    }

    public function test_index_returns_empty_when_trainer_has_no_formations(): void
    {
        $trainer = User::factory()->create(['role' => 'formateur']);
        $token = $trainer->createToken('phpunit')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/formateur/enrollments');

        $response
            ->assertStatus(200)
            ->assertJsonPath('data', []);
    }

    public function test_show_by_formation_returns_learners_when_owner(): void
    {
        $trainer = User::factory()->create(['role' => 'formateur']);
        $token = $trainer->createToken('phpunit')->plainTextToken;

        $formation = $this->makeFormation($trainer->id, 'Mon cours');

        $learnerA = $this->makeLearner();
        $learnerB = $this->makeLearner();
        $this->enroll($learnerA, $formation, 10);
        $this->enroll($learnerB, $formation, 80);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/formateur/formations/' . $formation->id . '/learners');

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.formation_id', $formation->id)
            ->assertJsonPath('data.formation_title', 'Mon cours')
            ->assertJsonPath('data.count', 2);

        $emails = collect($response->json('data.learners'))->pluck('user_email')->all();
        $this->assertContains($learnerA->email, $emails);
        $this->assertContains($learnerB->email, $emails);
    }

    public function test_show_by_formation_returns_403_when_not_owner(): void
    {
        $trainer = User::factory()->create(['role' => 'formateur']);
        $otherTrainer = User::factory()->create(['role' => 'formateur']);
        $token = $trainer->createToken('phpunit')->plainTextToken;

        $foreignFormation = $this->makeFormation($otherTrainer->id, 'Pas a moi');
        $this->enroll($this->makeLearner(), $foreignFormation);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/formateur/formations/' . $foreignFormation->id . '/learners');

        $response
            ->assertStatus(403)
            ->assertJsonPath('message', 'Vous ne pouvez consulter que les apprenants de vos formations');
    }

    public function test_show_by_formation_returns_403_for_apprenant(): void
    {
        $apprenant = User::factory()->create(['role' => 'apprenant']);
        $token = $apprenant->createToken('phpunit')->plainTextToken;

        $trainer = User::factory()->create(['role' => 'formateur']);
        $formation = $this->makeFormation($trainer->id);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/formateur/formations/' . $formation->id . '/learners');

        $response
            ->assertStatus(403)
            ->assertJsonPath('message', 'Action reservee aux formateurs');
    }

    public function test_show_by_formation_returns_zero_count_when_no_enrollment(): void
    {
        $trainer = User::factory()->create(['role' => 'formateur']);
        $token = $trainer->createToken('phpunit')->plainTextToken;

        $formation = $this->makeFormation($trainer->id, 'Cours vide');

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/formateur/formations/' . $formation->id . '/learners');

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.count', 0)
            ->assertJsonPath('data.learners', []);
    }

    public function test_index_includes_progress_and_email(): void
    {
        $trainer = User::factory()->create(['role' => 'formateur']);
        $token = $trainer->createToken('phpunit')->plainTextToken;

        $formation = $this->makeFormation($trainer->id, 'Mon cours');
        $learner = $this->makeLearner();
        $this->enroll($learner, $formation, 42);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/formateur/enrollments');

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.0.user_email', $learner->email)
            ->assertJsonPath('data.0.user_name', $learner->name)
            ->assertJsonPath('data.0.progress', 42)
            ->assertJsonPath('data.0.formation_title', 'Mon cours');
    }
}
