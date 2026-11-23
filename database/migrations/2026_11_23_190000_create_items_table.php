<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('items', function (Blueprint $table) {
            $table->id();
            $table->enum('kind', ['hiragana', 'katakana', 'radical', 'kanji', 'vocab']);
            $table->string('characters');
            $table->json('readings');
            $table->json('meanings');
            $table->unique(['kind', 'characters']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('items');
    }
};
