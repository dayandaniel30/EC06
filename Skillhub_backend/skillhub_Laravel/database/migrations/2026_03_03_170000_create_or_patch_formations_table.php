<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('formations')) {
            Schema::create('formations', function (Blueprint $table): void {
                $table->id();
                $table->string('title');
                $table->text('description')->nullable();
                $table->string('duration')->nullable();
                $table->string('level')->nullable();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->timestamps();
            });

            if (Schema::hasTable('users')) {
                Schema::table('formations', function (Blueprint $table): void {
                    $table->foreign('user_id')
                        ->references('id')
                        ->on('users')
                        ->nullOnDelete();
                });
            }

            return;
        }

        Schema::table('formations', function (Blueprint $table): void {
            if (!Schema::hasColumn('formations', 'title')) {
                $table->string('title')->nullable();
            }
            if (!Schema::hasColumn('formations', 'description')) {
                $table->text('description')->nullable();
            }
            if (!Schema::hasColumn('formations', 'duration')) {
                $table->string('duration')->nullable();
            }
            if (!Schema::hasColumn('formations', 'level')) {
                $table->string('level')->nullable();
            }
            if (!Schema::hasColumn('formations', 'user_id')) {
                $table->unsignedBigInteger('user_id')->nullable()->index();
            }
            if (!Schema::hasColumn('formations', 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }
            if (!Schema::hasColumn('formations', 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('formations')) {
            return;
        }

        Schema::table('formations', function (Blueprint $table): void {
            try {
                $table->dropForeign(['user_id']);
            } catch (\Throwable $e) {
            }
        });

        Schema::dropIfExists('formations');
    }
};
