<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ratings')) {
            return;
        }

        Schema::create('ratings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('formation_id');
            $table->unsignedTinyInteger('score');
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'formation_id']);
            $table->index('formation_id');

            if (Schema::hasTable('users')) {
                $table->foreign('user_id')
                    ->references('id')
                    ->on('users')
                    ->cascadeOnDelete();
            }
            if (Schema::hasTable('formations')) {
                $table->foreign('formation_id')
                    ->references('id')
                    ->on('formations')
                    ->cascadeOnDelete();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('ratings')) {
            return;
        }

        Schema::table('ratings', function (Blueprint $table): void {
            try {
                $table->dropForeign(['user_id']);
            } catch (\Throwable $e) {
            }
            try {
                $table->dropForeign(['formation_id']);
            } catch (\Throwable $e) {
            }
        });

        Schema::dropIfExists('ratings');
    }
};
