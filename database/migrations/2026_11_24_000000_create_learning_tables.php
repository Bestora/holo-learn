<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('stage')->default(0);
            $table->timestamp('due_at')->index();
            $table->foreignId('last_reference_id')->nullable()->constrained('refs')->nullOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'item_id']);
        });

        Schema::create('lessons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('size');
            $table->unsignedTinyInteger('position')->default(0);
            $table->unsignedTinyInteger('correct')->default(0);
            $table->unsignedTinyInteger('wrong')->default(0);
            $table->unsignedTinyInteger('new_items')->default(0);
            $table->json('state');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('favorites', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('talent_id')->constrained('talents')->cascadeOnDelete();
            $table->primary(['user_id', 'talent_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('specials_enabled')->default(true);
            $table->unsignedInteger('streak_days')->default(0);
            $table->date('last_lesson_on')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['specials_enabled', 'streak_days', 'last_lesson_on']);
        });
        Schema::dropIfExists('favorites');
        Schema::dropIfExists('lessons');
        Schema::dropIfExists('reviews');
    }
};
