<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dict_kanji', function (Blueprint $table) {
            $table->string('character')->primary();
            $table->json('readings');
            $table->json('meanings');
            $table->json('radicals');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dict_kanji');
    }
};
