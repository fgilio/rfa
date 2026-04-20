<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviewed_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('repo_path');
            $table->string('file_path');
            $table->string('content_hash');
            $table->timestamps();

            $table->unique(['project_id', 'file_path', 'content_hash'], 'reviewed_files_project_unique');
            $table->unique(['repo_path', 'file_path', 'content_hash'], 'reviewed_files_repo_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviewed_files');
    }
};
