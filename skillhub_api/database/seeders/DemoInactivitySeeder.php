<?php

namespace Database\Seeders;

use App\Models\Enrollment;
use App\Models\Formation;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Jeu de demonstration de la desinscription automatique pour inactivite.
 *
 * Cree un formateur, ses formations et un apprenant par etat possible de la
 * regle, afin que le tableau de bord affiche toute la palette de statuts sans
 * avoir a attendre 30 jours :
 *
 *   Alice  - active aujourd'hui                  -> Actif
 *   Bruno  - inactif depuis 26 jours             -> Inactif, J-4
 *   Chloe  - inactive depuis 45 jours            -> Desinscription imminente
 *   David  - inactif depuis 60 jours, fini a 100 -> Formation terminee (jamais retire)
 *   Emma   - aucune activite enregistree         -> repli sur la date d'inscription
 *
 * Idempotent : reexecuter le seeder remet les memes comptes dans le meme etat.
 *
 *   php artisan db:seed --class=DemoInactivitySeeder
 */
class DemoInactivitySeeder extends Seeder
{
    /**
     * Mot de passe commun aux comptes de demonstration.
     */
    private const DEMO_PASSWORD = 'Skillhub123!';

    public function run(): void
    {
        $trainer = User::updateOrCreate(
            ['email' => 'formateur@skillhub.test'],
            [
                'name' => 'Camille Formateur',
                'password' => self::DEMO_PASSWORD,
                'role' => 'formateur',
                'last_activity_at' => now(),
            ]
        );

        $formations = collect([
            ['Docker & CI/CD', 'Conteneurisation et chaine de deploiement continue.', '12h', 'intermediate'],
            ['Laravel 12 avance', 'Architecture, tests et taches planifiees.', '20h', 'advanced'],
            ['React pour formateurs', 'Construire un tableau de bord pedagogique.', '15h', 'beginner'],
        ])->map(fn (array $row): Formation => Formation::updateOrCreate(
            ['title' => $row[0]],
            [
                'description' => $row[1],
                'duration' => $row[2],
                'level' => $row[3],
                'user_id' => $trainer->id,
            ]
        ))->all();

        // [nom, email, jours d'inactivite (null = jamais), formation, progression, jours depuis l'inscription]
        $learners = [
            ['Alice Martin', 'alice@skillhub.test', 0, 0, 0, 5],
            ['Bruno Leroy', 'bruno@skillhub.test', 26, 1, 40, 30],
            ['Chloe Dubois', 'chloe@skillhub.test', 45, 2, 15, 50],
            ['David Nguyen', 'david@skillhub.test', 60, 0, 100, 70],
            ['Emma Petit', 'emma@skillhub.test', null, 1, 20, 12],
        ];

        foreach ($learners as [$name, $email, $inactiveDays, $formationIndex, $progress, $enrolledDaysAgo]) {
            $learner = User::updateOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'password' => self::DEMO_PASSWORD,
                    'role' => 'apprenant',
                    'last_activity_at' => $inactiveDays === null ? null : now()->subDays($inactiveDays),
                ]
            );

            Enrollment::updateOrCreate(
                ['user_id' => $learner->id, 'formation_id' => $formations[$formationIndex]->id],
                [
                    'progress' => $progress,
                    'enrolled_at' => now()->subDays($enrolledDaysAgo)->toDateTimeString(),
                ]
            );
        }

        $this->command?->info(sprintf(
            'Demo prete : %s / %s, %d formations, %d inscriptions.',
            $trainer->email,
            self::DEMO_PASSWORD,
            count($formations),
            count($learners)
        ));
    }
}
