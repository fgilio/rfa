<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comments', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('repo_path');
            $table->string('origin_ref');
            $table->string('file_path');
            $table->string('side');
            $table->unsignedInteger('start_line')->nullable();
            $table->unsignedInteger('end_line')->nullable();
            $table->string('file_content_hash')->nullable();
            $table->text('line_snippet')->nullable();
            $table->text('body');
            $table->boolean('is_draft')->default(false);
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'submitted_at']);
            $table->index(['repo_path', 'submitted_at']);
            $table->index(['project_id', 'file_path']);
            $table->index(['repo_path', 'file_path']);
            $table->index('file_content_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comments');
    }
};
