<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ajoute le statut de compte necessaire a la regle metier d'inactivite (Q1).
 *
 * - `is_active`           : un compte desactive ne peut plus obtenir de session SSO.
 * - `deactivated_at`      : horodatage de la desactivation, pour l'audit.
 * - `deactivation_reason` : motif court (ex. `inactivity_180d`), pour le reporting.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('last_activity_at');
            }

            if (! Schema::hasColumn('users', 'deactivated_at')) {
                $table->dateTime('deactivated_at')->nullable()->after('is_active');
            }

            if (! Schema::hasColumn('users', 'deactivation_reason')) {
                $table->string('deactivation_reason', 64)->nullable()->after('deactivated_at');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            foreach (['deactivation_reason', 'deactivated_at', 'is_active'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
