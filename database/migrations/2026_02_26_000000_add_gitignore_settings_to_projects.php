<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('global_gitignore_path')->nullable()->after('branch');
            $table->boolean('respect_global_gitignore')->default(true)->after('global_gitignore_path');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['global_gitignore_path', 'respect_global_gitignore']);
        });
    }
};
