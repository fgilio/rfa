<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('repo_path')->unique();
            $table->json('viewed_files')->default('[]');
            $table->json('comments')->default('[]');
            $table->text('global_comment')->default('');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_sessions');
    }
};
