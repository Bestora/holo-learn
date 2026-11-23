<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_reference', function (Blueprint $table) {
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reference_id')->constrained('refs')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('context_reading')->nullable();
            $table->primary(['item_id', 'reference_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_reference');
    }
};
