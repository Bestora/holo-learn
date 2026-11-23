<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('occurrences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reference_id')->constrained('refs')->cascadeOnDelete();
            $table->foreignId('talent_id')->constrained('talents')->cascadeOnDelete();
            $table->string('video_id')->nullable();
            $table->unsignedInteger('start_s')->nullable();
            $table->unsignedInteger('end_s')->nullable();
            $table->string('source_id')->unique();
            $table->timestamp('dead_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('occurrences');
    }
};
