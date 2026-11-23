<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Song titles can exceed 255 characters; the unique index sits on text_key, not on the texts.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refs', function (Blueprint $table) {
            $table->text('text_ja')->change();
            $table->text('alt_text')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('refs', function (Blueprint $table) {
            $table->string('text_ja')->change();
            $table->string('alt_text')->nullable()->change();
        });
    }
};
