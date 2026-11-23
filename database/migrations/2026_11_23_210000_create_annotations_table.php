<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('annotations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reference_id')->unique()->constrained('refs')->cascadeOnDelete();
            $table->text('reading_kana'); // song titles can exceed 255 characters
            $table->string('meaning_en', 300);
            $table->json('words');
            $table->enum('alt_kind', ['romaji', 'translation', 'none']);
            $table->string('model', 50);
            $table->json('check_errors')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('annotations');
    }
};
