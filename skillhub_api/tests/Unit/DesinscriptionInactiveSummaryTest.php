<?php

namespace Tests\Unit;

use App\Console\Commands\DesinscriptionInactive;
use PHPUnit\Framework\TestCase;

/**
 * Test unitaire du message de synthese : aucune base de donnees requise,
 * summaryMessage() est une fonction pure.
 */
class DesinscriptionInactiveSummaryTest extends TestCase
{
    public function test_it_announces_the_number_of_unenrollments(): void
    {
        $this->assertSame(
            '3 desinscription(s) effectuee(s) pour inactivite de plus de 30 jours.',
            DesinscriptionInactive::summaryMessage(3, 30)
        );
    }

    public function test_it_reports_zero_explicitly(): void
    {
        $this->assertSame(
            '0 desinscription effectuee : aucun apprenant inactif depuis plus de 30 jours.',
            DesinscriptionInactive::summaryMessage(0, 30)
        );
    }

    public function test_zero_stays_explicit_even_in_dry_run(): void
    {
        $this->assertSame(
            DesinscriptionInactive::summaryMessage(0, 30),
            DesinscriptionInactive::summaryMessage(0, 30, true)
        );
    }

    public function test_dry_run_states_that_nothing_was_deleted(): void
    {
        $message = DesinscriptionInactive::summaryMessage(2, 30, true);

        $this->assertStringContainsString('2 desinscription(s)', $message);
        $this->assertStringContainsString('aucune suppression effectuee', $message);
        $this->assertStringNotContainsString('effectuee(s)', $message);
    }

    public function test_it_uses_the_configured_threshold(): void
    {
        $this->assertStringContainsString(
            'plus de 7 jours',
            DesinscriptionInactive::summaryMessage(1, 7)
        );
    }

    public function test_default_threshold_constant_is_thirty_days(): void
    {
        $this->assertSame(30, DesinscriptionInactive::DEFAULT_INACTIVITY_DAYS);
    }
}
