<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Planification des taches
|--------------------------------------------------------------------------
|
| Depuis Laravel 11, `app/Console/Kernel.php` n'existe plus : la planification
| se declare ici, et c'est ce fichier qui remplit le role decrit dans le cahier
| des charges. L'ordonnanceur est demarre par `php artisan schedule:work` dans
| le conteneur `skillhub-laravel` (voir docker/entrypoint.sh).
|
*/

// Q1 - Revoque les acces et desactive les comptes inactifs depuis plus de 6 mois.
Schedule::command('users:purge-inactive')
    ->dailyAt('02:30')
    ->withoutOverlapping()
    ->onOneServer()
    ->description('Desactivation des comptes inactifs depuis plus de 6 mois');

// Desinscription des apprenants inactifs depuis plus de 30 jours de leurs
// formations en cours. Regle distincte de la precedente : elle ne touche pas
// au compte, seulement aux inscriptions.
Schedule::command('app:desinscription-inactive')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->description('Desinscription des apprenants inactifs depuis plus de 30 jours');
