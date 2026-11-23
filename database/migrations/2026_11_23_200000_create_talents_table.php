<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('talents', function (Blueprint $table) {
            $table->id();
            $table->string('holodex_channel_id')->unique();
            $table->string('name_ja')->nullable();
            $table->string('name_en');
            $table->string('group_name')->nullable();
            $table->string('photo_url')->nullable();
            $table->boolean('inactive')->default(false);
            $table->boolean('is_secondary')->default(false);
            $table->unsignedSmallInteger('priority')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('talents');
    }
};
