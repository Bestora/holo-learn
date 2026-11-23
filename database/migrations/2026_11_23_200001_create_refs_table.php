<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refs', function (Blueprint $table) {
            $table->id();
            $table->enum('kind', ['name', 'song']);
            $table->string('text_ja');
            $table->char('text_key', 40);
            $table->string('alt_text')->nullable();
            $table->enum('status', ['new', 'annotated', 'verified', 'rejected'])->default('new');
            $table->unique(['kind', 'text_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refs');
    }
};
