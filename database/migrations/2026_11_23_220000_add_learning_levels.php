<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dict_kanji', function (Blueprint $table) {
            $table->unsignedTinyInteger('jlpt')->nullable(); // KANJIDIC2 old scale, 4 = easiest
            $table->unsignedTinyInteger('grade')->nullable();
            $table->unsignedSmallInteger('frequency')->nullable();
        });
        Schema::table('dict_words', function (Blueprint $table) {
            $table->boolean('common')->default(false);
        });
        Schema::table('items', function (Blueprint $table) {
            $table->unsignedSmallInteger('level')->default(0)->index();
            $table->boolean('is_special')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('dict_kanji', function (Blueprint $table) {
            $table->dropColumn(['jlpt', 'grade', 'frequency']);
        });
        Schema::table('dict_words', function (Blueprint $table) {
            $table->dropColumn('common');
        });
        Schema::table('items', function (Blueprint $table) {
            $table->dropIndex(['level']);
            $table->dropColumn(['level', 'is_special']);
        });
    }
};
