<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'last_activity_at')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dateTime('last_activity_at')->nullable()->after('role');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'last_activity_at')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropColumn('last_activity_at');
            });
        }
    }
};
