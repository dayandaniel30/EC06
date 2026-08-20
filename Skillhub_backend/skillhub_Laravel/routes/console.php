<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Desinscription automatique des apprenants inactifs depuis plus de 30 jours.
Schedule::command('app:desinscription-inactive')->dailyAt('03:00');
