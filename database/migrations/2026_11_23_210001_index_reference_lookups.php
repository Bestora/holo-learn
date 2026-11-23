<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// foreignId()->constrained() creates no index on SQLite; the export's per-reference
// subqueries scanned occurrences in full (minutes at 50k references).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('occurrences', function (Blueprint $table) {
            $table->index('reference_id');
            $table->index('talent_id');
        });
        Schema::table('item_reference', function (Blueprint $table) {
            $table->index('reference_id'); // the primary key starts with item_id
        });
    }

    public function down(): void
    {
        Schema::table('occurrences', function (Blueprint $table) {
            $table->dropIndex(['reference_id']);
            $table->dropIndex(['talent_id']);
        });
        Schema::table('item_reference', function (Blueprint $table) {
            $table->dropIndex(['reference_id']);
        });
    }
};
