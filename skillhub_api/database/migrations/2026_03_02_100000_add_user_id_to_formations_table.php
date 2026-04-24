<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('formations') && !Schema::hasColumn('formations', 'user_id')) {
            Schema::table('formations', function (Blueprint $table): void {
                $table->unsignedBigInteger('user_id')->nullable()->after('id')->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('formations') && Schema::hasColumn('formations', 'user_id')) {
            Schema::table('formations', function (Blueprint $table): void {
                $table->dropColumn('user_id');
            });
        }
    }
};
