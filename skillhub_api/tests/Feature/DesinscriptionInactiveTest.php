<?php

namespace Tests\Feature;

use App\Models\Enrollment;
use App\Models\Formation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class DesinscriptionInactiveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Horloge figee sur une seconde pleine : le seuil est calcule dans la
        // commande et la colonne DATETIME ne stocke pas les microsecondes,
        // ce qui rendrait instable le test de la borne exacte a 30 jours.
        $this->travelTo(Carbon::now()->startOfSecond());
    }

    private function makeFormation(string $title): Formation
    {
        return Formation::create([
            'title' => $title,
            'duration' => '10h',
            'level' => 'beginner',
        ]);
    }

    private function enroll(
        User $user,
        Formation $formation,
        int $progress = 0,
        ?string $enrolledAt = null
    ): Enrollment {
        return Enrollment::create([
            'user_id' => $user->id,
            'formation_id' => $formation->id,
            'progress' => $progress,
            'enrolled_at' => $enrolledAt ?? now(),
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

    public function test_inactive_learner_is_unenrolled_from_all_active_formations(): void
    {
        $learner = $this->learner(31);

        $first = $this->enroll($learner, $this->makeFormation('Formation A'));
        $second = $this->enroll($learner, $this->makeFormation('Formation B'), 40);

        $this->artisan('app:desinscription-inactive')
            ->expectsOutputToContain('2 desinscription(s) effectuee(s) pour inactivite de plus de 30 jours.')
            ->assertSuccessful();

        $this->assertDatabaseMissing('enrollments', ['id' => $first->id]);
        $this->assertDatabaseMissing('enrollments', ['id' => $second->id]);
        $this->assertSame(0, Enrollment::where('user_id', $learner->id)->count());
    }

    public function test_recently_active_learner_keeps_his_enrollments(): void
    {
        $learner = $this->learner(29);

        $enrollment = $this->enroll($learner, $this->makeFormation('Formation A'));

        $this->artisan('app:desinscription-inactive')
            ->expectsOutputToContain('0 desinscription effectuee')
            ->assertSuccessful();

        $this->assertDatabaseHas('enrollments', ['id' => $enrollment->id]);
    }

    public function test_exactly_thirty_days_of_inactivity_is_not_enough(): void
    {
        $learner = $this->learner(30);

        $enrollment = $this->enroll($learner, $this->makeFormation('Formation A'));

        $this->artisan('app:desinscription-inactive')->assertSuccessful();

        $this->assertDatabaseHas('enrollments', ['id' => $enrollment->id]);
    }

    public function test_completed_formations_are_not_touched(): void
    {
        $learner = $this->learner(90);

        $completed = $this->enroll($learner, $this->makeFormation('Terminee'), 100);
        $inProgress = $this->enroll($learner, $this->makeFormation('En cours'), 10);

        $this->artisan('app:desinscription-inactive')->assertSuccessful();

        $this->assertDatabaseHas('enrollments', ['id' => $completed->id]);
        $this->assertDatabaseMissing('enrollments', ['id' => $inProgress->id]);
    }

    public function test_other_learners_are_not_affected(): void
    {
        $inactive = $this->learner(45);
        $active = $this->learner(2);

        $formation = $this->makeFormation('Formation partagee');

        $inactiveEnrollment = $this->enroll($inactive, $formation);
        $activeEnrollment = $this->enroll($active, $formation);

        $this->artisan('app:desinscription-inactive')->assertSuccessful();

        $this->assertDatabaseMissing('enrollments', ['id' => $inactiveEnrollment->id]);
        $this->assertDatabaseHas('enrollments', ['id' => $activeEnrollment->id]);
    }

    public function test_enrollment_date_is_used_when_no_activity_was_ever_recorded(): void
    {
        $learner = $this->learner(null);

        $old = $this->enroll(
            $learner,
            $this->makeFormation('Inscription ancienne'),
            0,
            now()->subDays(45)->toDateTimeString()
        );
        $recent = $this->enroll(
            $learner,
            $this->makeFormation('Inscription recente'),
            0,
            now()->subDays(5)->toDateTimeString()
        );

        $this->artisan('app:desinscription-inactive')->assertSuccessful();

        $this->assertDatabaseMissing('enrollments', ['id' => $old->id]);
        $this->assertDatabaseHas('enrollments', ['id' => $recent->id]);
    }

    public function test_dry_run_reports_without_deleting(): void
    {
        $learner = $this->learner(60);

        $enrollment = $this->enroll($learner, $this->makeFormation('Formation A'));

        $this->artisan('app:desinscription-inactive', ['--dry-run' => true])
            ->expectsOutputToContain('1 desinscription(s) a effectuer pour inactivite de plus de 30 jours (--dry-run : aucune suppression effectuee).')
            ->assertSuccessful();

        $this->assertDatabaseHas('enrollments', ['id' => $enrollment->id]);
    }

    public function test_days_option_overrides_the_default_threshold(): void
    {
        $learner = $this->learner(10);

        $enrollment = $this->enroll($learner, $this->makeFormation('Formation A'));

        $this->artisan('app:desinscription-inactive')->assertSuccessful();
        $this->assertDatabaseHas('enrollments', ['id' => $enrollment->id]);

        $this->artisan('app:desinscription-inactive', ['--days' => 7])->assertSuccessful();
        $this->assertDatabaseMissing('enrollments', ['id' => $enrollment->id]);
    }

    public function test_invalid_days_option_is_rejected(): void
    {
        $learner = $this->learner(60);

        $enrollment = $this->enroll($learner, $this->makeFormation('Formation A'));

        $this->artisan('app:desinscription-inactive', ['--days' => 0])->assertFailed();

        $this->assertDatabaseHas('enrollments', ['id' => $enrollment->id]);
    }

    public function test_a_summary_log_records_the_number_of_unenrollments(): void
    {
        Log::spy();

        $learner = $this->learner(45);
        $this->enroll($learner, $this->makeFormation('Formation A'));
        $this->enroll($learner, $this->makeFormation('Formation B'));

        $this->artisan('app:desinscription-inactive')->assertSuccessful();

        Log::shouldHaveReceived('info')
            ->withArgs(function (string $message, array $context): bool {
                return ($context['unenrolled'] ?? null) === 2
                    && ($context['days'] ?? null) === 30
                    && ($context['dry_run'] ?? null) === false
                    && str_contains($message, '2 desinscription(s) effectuee(s)');
            })
            ->once();
    }

    public function test_summary_log_is_written_even_when_nothing_is_unenrolled(): void
    {
        Log::spy();

        $learner = $this->learner(3);
        $this->enroll($learner, $this->makeFormation('Formation A'));

        $this->artisan('app:desinscription-inactive')->assertSuccessful();

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message, array $context): bool => ($context['unenrolled'] ?? null) === 0)
            ->once();
    }

    public function test_dry_run_summary_log_is_flagged_as_such(): void
    {
        Log::spy();

        $learner = $this->learner(45);
        $this->enroll($learner, $this->makeFormation('Formation A'));

        $this->artisan('app:desinscription-inactive', ['--dry-run' => true])
            ->assertSuccessful();

        Log::shouldHaveReceived('info')
            ->withArgs(function (string $message, array $context): bool {
                return ($context['unenrolled'] ?? null) === 1
                    && ($context['dry_run'] ?? null) === true
                    && str_contains($message, 'aucune suppression effectuee');
            })
            ->once();
    }
}
