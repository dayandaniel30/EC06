<?php

namespace App\Providers;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->environment('testing')) {
            return;
        }

        // Force MySQL database target to skillhubsql for all requests.
        Config::set('database.connections.mysql.database', 'skillhubsql');

        if (Config::get('database.default') === 'mysql') {
            DB::purge('mysql');
            DB::reconnect('mysql');
        }
    }
}
