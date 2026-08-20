<?php

namespace Tests\Feature;

use App\Models\Enrollment;
use App\Models\Formation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Espace apprenant : creation de compte et consultation des inscriptions.
 *
 * Le role `apprenant` etait refuse a l'inscription et le catalogue lisait des
 * colonnes inexistantes : ces tests verrouillent les deux corrections, ainsi
 * que le suivi d'inactivite desormais expose a l'apprenant lui-meme.
 */
class LearnerSpaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // La colonne DATETIME ne stocke pas les microsecondes : figer l'horloge
        // sur une seconde pleine rend stable le calcul en jours.
        $this->travelTo(Carbon::now()->startOfSecond());
    }

    private function makeFormation(string $title = 'Formation X'): Formation
    {
        return Formation::create([
            'title' => $title,
            'description' => 'Description de '.$title,
            'duration' => '8h',
            'level' => 'beginner',
        ]);
    }

    private function learner(?int $inactiveSinceDays = 0): User
    {
        return User::factory()->create([
            'role' => 'apprenant',
            'last_activity_at' => $inactiveSinceDays === null
                ? null
                : now()->subDays($inactiveSinceDays),
        ]);
    }

    private function actingAsLearner(User $learner): self
    {
        return $this->withHeader(
            'Authorization',
            'Bearer '.$learner->createToken('phpunit')->plainTextToken
        );
    }

    public function test_an_account_can_be_created_with_the_apprenant_role(): void
    {
        $this->postJson('/api/register', [
            'name' => 'Nouvel Apprenant',
            'email' => 'nouvel.apprenant@skillhub.test',
            'password' => 'Skillhub123!',
            'role' => 'apprenant',
        ])
            ->assertCreated()
            ->assertJsonPath('user.role', 'apprenant');

        $this->assertDatabaseHas('users', [
            'email' => 'nouvel.apprenant@skillhub.test',
            'role' => 'apprenant',
        ]);
    }

    public function test_registering_from_the_dashboard_origin_is_not_blocked_by_csrf(): void
    {
        // `sanctum.stateful` liste `localhost:3000` par defaut. Avec
        // statefulApi(), cet appel basculait en mode session et repartait en
        // 419 « CSRF token mismatch », alors que le dashboard s'authentifie
        // uniquement par jeton Bearer.
        $this->withHeaders([
            'Origin' => 'http://localhost:3000',
            'Referer' => 'http://localhost:3000/',
        ])->postJson('/api/register', [
            'name' => 'Apprenant Navigateur',
            'email' => 'navigateur@skillhub.test',
            'password' => 'Skillhub123!',
            'role' => 'apprenant',
        ])->assertCreated();
    }

    public function test_an_unknown_role_is_still_refused(): void
    {
        $this->postJson('/api/register', [
            'name' => 'Intrus',
            'email' => 'intrus@skillhub.test',
            'password' => 'Skillhub123!',
            'role' => 'admin',
        ])->assertStatus(422);

        $this->assertDatabaseMissing('users', ['email' => 'intrus@skillhub.test']);
    }

    public function test_the_catalogue_returns_the_real_description(): void
    {
        // La description etait lue sur short_description / full_description,
        // absentes de la table : elle revenait donc toujours vide.
        $this->makeFormation('Docker');

        $this->actingAsLearner($this->learner())
            ->getJson('/api/learner/formations')
            ->assertOk()
            ->assertJsonPath('data.0.description', 'Description de Docker');
    }

    public function test_the_catalogue_flags_formations_already_joined(): void
    {
        $learner = $this->learner();
        $joined = $this->makeFormation('Deja suivie');
        $available = $this->makeFormation('Disponible');

        Enrollment::create([
            'user_id' => $learner->id,
            'formation_id' => $joined->id,
            'progress' => 0,
            'enrolled_at' => now()->toDateTimeString(),
        ]);

        $response = $this->actingAsLearner($learner)
            ->getJson('/api/learner/formations')
            ->assertOk();

        $byId = collect($response->json('data'))->keyBy('id');

        $this->assertTrue($byId[$joined->id]['is_enrolled']);
        $this->assertFalse($byId[$available->id]['is_enrolled']);
    }

    public function test_the_catalogue_announces_the_business_rules(): void
    {
        $this->actingAsLearner($this->learner())
            ->getJson('/api/learner/formations')
            ->assertOk()
            ->assertJsonPath('meta.max_active', 5)
            ->assertJsonPath('meta.unenroll_after_days', 30);
    }

    public function test_my_enrollments_show_the_countdown_before_unenrollment(): void
    {
        $learner = $this->learner(26);

        Enrollment::create([
            'user_id' => $learner->id,
            'formation_id' => $this->makeFormation()->id,
            'progress' => 30,
            'enrolled_at' => now()->subDays(60)->toDateTimeString(),
        ]);

        $this->actingAsLearner($learner)
            ->getJson('/api/learner/enrollments')
            ->assertOk()
            ->assertJsonPath('data.0.inactive_days', 26)
            ->assertJsonPath('data.0.days_before_unenrollment', 4)
            ->assertJsonPath('meta.unenroll_after_days', 30);
    }

    public function test_a_completed_enrollment_shows_no_countdown(): void
    {
        $learner = $this->learner(365);

        Enrollment::create([
            'user_id' => $learner->id,
            'formation_id' => $this->makeFormation()->id,
            'progress' => 100,
            'enrolled_at' => now()->subDays(400)->toDateTimeString(),
        ]);

        $this->actingAsLearner($learner)
            ->getJson('/api/learner/enrollments')
            ->assertOk()
            ->assertJsonPath('data.0.days_before_unenrollment', null);
    }

    public function test_the_enrollment_limit_message_follows_the_configuration(): void
    {
        config(['skillhub.enrollment.max_active' => 2]);

        $learner = $this->learner();

        foreach (['A', 'B'] as $title) {
            Enrollment::create([
                'user_id' => $learner->id,
                'formation_id' => $this->makeFormation($title)->id,
                'progress' => 0,
                'enrolled_at' => now()->toDateTimeString(),
            ]);
        }

        $this->actingAsLearner($learner)
            ->postJson('/api/learner/enrollments', ['formation_id' => $this->makeFormation('C')->id])
            ->assertStatus(400)
            ->assertJsonPath('message', 'Vous ne pouvez pas etre inscrit a plus de 2 formations simultanement');
    }
}
