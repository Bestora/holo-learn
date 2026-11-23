<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dict_words', function (Blueprint $table) {
            $table->id();
            $table->string('spelling');
            $table->string('reading');
            $table->json('glosses');
            $table->boolean('is_name');
            $table->index(['spelling', 'reading']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dict_words');
    }
};
