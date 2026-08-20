<?php

namespace App\Console\Commands;

use App\Models\Enrollment;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Desinscrit les apprenants inactifs de toutes leurs formations en cours.
 *
 * Une formation est "en cours" tant que sa progression est nulle ou < 100.
 * La derniere activite est lue sur users.last_activity_at ; quand cette
 * colonne n'a jamais ete alimentee, la date d'inscription sert de repere.
 */
class DesinscriptionInactive extends Command
{
    /**
     * Seuil d'inactivite par defaut, en jours.
     */
    public const DEFAULT_INACTIVITY_DAYS = 30;

    protected $signature = "app:desinscription-inactive
                            {--days= : Seuil d'inactivite en jours (30 par defaut)}
                            {--dry-run : Liste les desinscriptions sans les appliquer}";

    protected $description = 'Desinscrit automatiquement les apprenants inactifs de leurs formations en cours';

    public function handle(): int
    {
        $days = $this->option('days') === null
            ? (int) config('skillhub.inactivity.unenroll_after_days', self::DEFAULT_INACTIVITY_DAYS)
            : (int) $this->option('days');

        if ($days < 1) {
            $this->error("L'option --days doit etre un entier superieur ou egal a 1.");

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $threshold = Carbon::now()->subDays($days);

        $rows = [];

        Enrollment::query()
            ->with('user')
            ->where(function ($query): void {
                $query->whereNull('progress')->orWhere('progress', '<', 100);
            })
            ->chunkById(200, function (Collection $enrollments) use ($threshold, $dryRun, &$rows): void {
                foreach ($enrollments as $enrollment) {
                    $lastActivity = $this->resolveLastActivity($enrollment);

                    if ($lastActivity === null || $lastActivity->greaterThanOrEqualTo($threshold)) {
                        continue;
                    }

                    $rows[] = [
                        (int) $enrollment->id,
                        (string) ($enrollment->user->email ?? 'utilisateur inconnu'),
                        (int) $enrollment->formation_id,
                        $lastActivity->toDateTimeString(),
                        (int) $lastActivity->diffInDays(Carbon::now()),
                    ];

                    if (! $dryRun) {
                        $enrollment->delete();

                        Log::info('Desinscription automatique pour inactivite', [
                            'enrollment_id' => (int) $enrollment->id,
                            'user_id' => (int) $enrollment->user_id,
                            'formation_id' => (int) $enrollment->formation_id,
                            'last_activity_at' => $lastActivity->toDateTimeString(),
                        ]);
                    }
                }
            });

        $count = count($rows);

        if ($count > 0) {
            $this->table(
                ['Inscription', 'Apprenant', 'Formation', 'Derniere activite', 'Jours inactif'],
                $rows
            );
        }

        $message = self::summaryMessage($count, $days, $dryRun);

        $this->info($message);

        Log::info($message, [
            'command' => 'app:desinscription-inactive',
            'days' => $days,
            'dry_run' => $dryRun,
            'unenrolled' => $count,
        ]);

        return self::SUCCESS;
    }

    /**
     * Message de synthese annoncant le nombre de desinscriptions.
     */
    public static function summaryMessage(int $count, int $days, bool $dryRun = false): string
    {
        if ($count === 0) {
            return "0 desinscription effectuee : aucun apprenant inactif depuis plus de {$days} jours.";
        }

        if ($dryRun) {
            return "{$count} desinscription(s) a effectuer pour inactivite de plus de {$days} jours "
                .'(--dry-run : aucune suppression effectuee).';
        }

        return "{$count} desinscription(s) effectuee(s) pour inactivite de plus de {$days} jours.";
    }

    /**
     * Date de reference servant a mesurer l'inactivite de l'apprenant.
     */
    private function resolveLastActivity(Enrollment $enrollment): ?CarbonInterface
    {
        $lastActivityAt = $enrollment->user?->last_activity_at;

        if ($lastActivityAt !== null) {
            return Carbon::parse($lastActivityAt);
        }

        if (! empty($enrollment->enrolled_at)) {
            return Carbon::parse($enrollment->enrolled_at);
        }

        return null;
    }
}
