<?php

namespace Tests\Feature;

use App\Models\Enrollment;
use App\Models\Formation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Suivi d'inactivite expose au formateur.
 *
 * Le tableau de bord doit annoncer la desinscription automatique avant qu'elle
 * ne soit appliquee : ces tests verrouillent le contrat de l'API sur lequel il
 * s'appuie, et surtout le fait qu'il suive les memes regles que la commande
 * `app:desinscription-inactive`.
 */
class TrainerLearnerInactivityTest extends TestCase
{
    use RefreshDatabase;

    private User $trainer;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        // Horloge figee sur une seconde pleine : la colonne DATETIME ne stocke
        // pas les microsecondes, ce qui rendrait instable le calcul en jours.
        $this->travelTo(Carbon::now()->startOfSecond());

        $this->trainer = User::factory()->create(['role' => 'formateur']);
        $this->token = $this->trainer->createToken('phpunit')->plainTextToken;
    }

    private function makeFormation(string $title = 'Formation X'): Formation
    {
        $formation = Formation::create([
            'title' => $title,
            'description' => 'Description',
            'duration' => '8h',
            'level' => 'beginner',
        ]);

        $formation->user_id = $this->trainer->id;
        $formation->save();

        return $formation;
    }

    private function enroll(
        User $learner,
        Formation $formation,
        int $progress = 0,
        ?string $enrolledAt = null
    ): Enrollment {
        return Enrollment::create([
            'user_id' => $learner->id,
            'formation_id' => $formation->id,
            'progress' => $progress,
            'enrolled_at' => $enrolledAt ?? now()->toDateTimeString(),
        ]);
    }

    private function learner(?int $inactiveSinceDays): User
    {
        return User::factory()->create([
            'role' => 'apprenant',
            'last_activity_at' => $inactiveSinceDays === null
                ? null
                : now()->subDays($inactiveSinceDays),
        ]);
    }

    private function index(): \Illuminate\Testing\TestResponse
    {
        return $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson('/api/formateur/enrollments');
    }

    public function test_response_announces_the_unenrollment_threshold(): void
    {
        $this->enroll($this->learner(1), $this->makeFormation());

        $this->index()
            ->assertOk()
            ->assertJsonPath('meta.unenroll_after_days', 30);
    }

    public function test_threshold_is_announced_even_without_any_formation(): void
    {
        $this->index()
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.unenroll_after_days', 30);
    }

    public function test_recently_active_learner_has_days_left_before_unenrollment(): void
    {
        $this->enroll($this->learner(5), $this->makeFormation());

        $this->index()
            ->assertOk()
            ->assertJsonPath('data.0.inactive_days', 5)
            ->assertJsonPath('data.0.days_before_unenrollment', 25);
    }

    public function test_learner_past_the_threshold_has_no_day_left(): void
    {
        $this->enroll($this->learner(45), $this->makeFormation());

        $this->index()
            ->assertOk()
            ->assertJsonPath('data.0.inactive_days', 45)
            ->assertJsonPath('data.0.days_before_unenrollment', 0);
    }

    public function test_completed_enrollment_is_never_flagged(): void
    {
        // Progression a 100 : la commande ne la desinscrit pas, l'API ne doit
        // donc annoncer aucune echeance, quelle que soit l'inactivite.
        $this->enroll($this->learner(365), $this->makeFormation(), 100);

        $this->index()
            ->assertOk()
            ->assertJsonPath('data.0.days_before_unenrollment', null);
    }

    public function test_enrollment_date_is_used_when_no_activity_was_ever_recorded(): void
    {
        $this->enroll(
            $this->learner(null),
            $this->makeFormation(),
            0,
            now()->subDays(12)->toDateTimeString()
        );

        $this->index()
            ->assertOk()
            ->assertJsonPath('data.0.last_activity_at', null)
            ->assertJsonPath('data.0.inactive_days', 12)
            ->assertJsonPath('data.0.days_before_unenrollment', 18);
    }

    public function test_last_activity_is_exposed_as_a_datetime(): void
    {
        $learner = $this->learner(3);
        $this->enroll($learner, $this->makeFormation());

        $this->index()
            ->assertOk()
            ->assertJsonPath(
                'data.0.last_activity_at',
                $learner->last_activity_at->toDateTimeString()
            );
    }

    public function test_threshold_follows_the_configuration(): void
    {
        config(['skillhub.inactivity.unenroll_after_days' => 10]);

        $this->enroll($this->learner(4), $this->makeFormation());

        $this->index()
            ->assertOk()
            ->assertJsonPath('meta.unenroll_after_days', 10)
            ->assertJsonPath('data.0.days_before_unenrollment', 6);
    }

    public function test_formation_scoped_endpoint_carries_the_same_data(): void
    {
        $formation = $this->makeFormation('Cours suivi');
        $this->enroll($this->learner(40), $formation);

        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson('/api/formateur/formations/'.$formation->id.'/learners')
            ->assertOk()
            ->assertJsonPath('meta.unenroll_after_days', 30)
            ->assertJsonPath('data.learners.0.inactive_days', 40)
            ->assertJsonPath('data.learners.0.days_before_unenrollment', 0);
    }
}
