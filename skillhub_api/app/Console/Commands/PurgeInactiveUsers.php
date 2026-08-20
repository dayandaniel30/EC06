<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Q1 - Desactive les comptes sans activite depuis plus de 6 mois.
 *
 * La commande s'appuie sur `users.last_activity_at`, alimente a chaque requete
 * API authentifiee par {@see \App\Http\Middleware\TrackLastActivity}. Pour chaque
 * compte au-dela du seuil elle :
 *
 *   1. revoque les jetons d'acces Sanctum (deconnexion immediate) ;
 *   2. passe `is_active` a false et horodate `deactivated_at` ;
 *   3. journalise l'operation pour l'audit.
 *
 * Un compte desactive conserve ses donnees metier : la desactivation est
 * reversible par un administrateur, contrairement a une suppression.
 *
 * @see \App\Console\Commands\DesinscriptionInactive pour la regle voisine, qui
 *      desinscrit des formations sans toucher au compte lui-meme.
 */
class PurgeInactiveUsers extends Command
{
    protected $signature = "users:purge-inactive
                            {--days= : Seuil d'inactivite en jours (defaut : config skillhub.inactivity.purge_after_days)}
                            {--dry-run : Liste les comptes concernes sans rien modifier}";

    protected $description = 'Revoque les acces et desactive les comptes inactifs depuis plus de 6 mois';

    public function handle(): int
    {
        $days = $this->resolveDays();

        if ($days === null) {
            $this->error("L'option --days doit etre un entier superieur ou egal a 1.");

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $threshold = Carbon::now()->subDays($days);

        $this->line(sprintf(
            'Seuil d\'inactivite : %s jours (comptes sans activite depuis le %s).',
            $days,
            $threshold->toDateTimeString(),
        ));

        $rows = [];
        $revokedTokens = 0;

        User::query()
            ->inactiveSince($threshold)
            ->chunkById(
                (int) config('skillhub.inactivity.chunk_size', 200),
                function (Collection $users) use ($dryRun, &$rows, &$revokedTokens): void {
                    /** @var User $user */
                    foreach ($users as $user) {
                        $rows[] = [
                            (int) $user->getKey(),
                            (string) $user->email,
                            (string) $user->role,
                            $user->last_activity_at?->toDateTimeString() ?? '-',
                            (int) $user->last_activity_at?->diffInDays(Carbon::now()),
                        ];

                        if (! $dryRun) {
                            $revokedTokens += $user->deactivate(User::DEACTIVATION_REASON_INACTIVITY);
                        }
                    }
                }
            );

        $count = count($rows);

        if ($count > 0) {
            $this->table(
                ['ID', 'Email', 'Role', 'Derniere activite', 'Jours inactif'],
                $rows,
            );
        }

        $message = self::summaryMessage($count, $days, $dryRun);
        $this->info($message);

        Log::info($message, [
            'command' => 'users:purge-inactive',
            'days' => $days,
            'dry_run' => $dryRun,
            'deactivated' => $dryRun ? 0 : $count,
            'revoked_tokens' => $revokedTokens,
            'threshold' => $threshold->toDateTimeString(),
        ]);

        return self::SUCCESS;
    }

    /**
     * Message de synthese de l'execution.
     *
     * Expose en statique pour etre teste sans lancer la commande complete.
     */
    public static function summaryMessage(int $count, int $days, bool $dryRun = false): string
    {
        if ($count === 0) {
            return "0 compte desactive : aucun utilisateur inactif depuis plus de {$days} jours.";
        }

        if ($dryRun) {
            return "{$count} compte(s) seraient desactives pour inactivite de plus de {$days} jours "
                .'(--dry-run : aucune modification appliquee).';
        }

        return "{$count} compte(s) desactive(s) et acces revoques pour inactivite de plus de {$days} jours.";
    }

    /**
     * Resout le seuil d'inactivite, depuis l'option ou la configuration.
     *
     * @return int|null le seuil en jours, ou null si l'option est invalide
     */
    private function resolveDays(): ?int
    {
        $option = $this->option('days');

        if ($option === null) {
            return (int) config('skillhub.inactivity.purge_after_days', 180);
        }

        if (! is_numeric($option) || (int) $option < 1) {
            return null;
        }

        return (int) $option;
    }
}
