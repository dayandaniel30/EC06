<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('enrollments')) {
            Schema::create('enrollments', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('user_id')->index();
                $table->unsignedBigInteger('formation_id')->index();
                $table->integer('progress')->default(0);
                $table->timestamp('enrolled_at')->useCurrent();
                $table->unique(['user_id', 'formation_id']);
            });

            if (Schema::hasTable('users') && Schema::hasTable('formations')) {
                Schema::table('enrollments', function (Blueprint $table): void {
                    $table->foreign('user_id')
                        ->references('id')
                        ->on('users')
                        ->cascadeOnDelete();

                    $table->foreign('formation_id')
                        ->references('id')
                        ->on('formations')
                        ->cascadeOnDelete();
                });
            }

            return;
        }

        Schema::table('enrollments', function (Blueprint $table): void {
            if (! Schema::hasColumn('enrollments', 'user_id')) {
                $table->unsignedBigInteger('user_id')->index();
            }
            if (! Schema::hasColumn('enrollments', 'formation_id')) {
                $table->unsignedBigInteger('formation_id')->index();
            }
            if (! Schema::hasColumn('enrollments', 'progress')) {
                $table->integer('progress')->default(0);
            }
            if (! Schema::hasColumn('enrollments', 'enrolled_at')) {
                $table->timestamp('enrolled_at')->useCurrent();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('enrollments')) {
            return;
        }

        Schema::table('enrollments', function (Blueprint $table): void {
            try {
                $table->dropForeign(['user_id']);
            } catch (\Throwable $e) {
            }

            try {
                $table->dropForeign(['formation_id']);
            } catch (\Throwable $e) {
            }
        });

        Schema::dropIfExists('enrollments');
    }
};
