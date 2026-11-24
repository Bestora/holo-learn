<?php

use App\Models\Invitation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invitations', function (Blueprint $table) {
            $table->unsignedInteger('max_uses')->default(1);
            $table->unsignedInteger('uses')->default(0);
            $table->timestamp('expires_at')->nullable();
        });

        // used_at now means "last used at"; a set value was the one use of a single-use invite.
        Invitation::whereNotNull('used_at')->update(['uses' => 1]);

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('invitation_id')->nullable()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('invitation_id');
        });

        Schema::table('invitations', function (Blueprint $table) {
            $table->dropColumn(['max_uses', 'uses', 'expires_at']);
        });
    }
};
