<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('review_sessions', function (Blueprint $table) {
            $table->string('last_view_mode')->nullable()->after('global_comment');
            $table->string('last_view_kind')->nullable()->after('last_view_mode');
            $table->string('last_view_from')->nullable()->after('last_view_kind');
            $table->string('last_view_to')->nullable()->after('last_view_from');
        });
    }

    public function down(): void
    {
        Schema::table('review_sessions', function (Blueprint $table) {
            $table->dropColumn(['last_view_mode', 'last_view_kind', 'last_view_from', 'last_view_to']);
        });
    }
};
