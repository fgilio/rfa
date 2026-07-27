<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comment_replies', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('comment_id');
            $table->string('author_type');
            $table->string('author_key', 100);
            $table->string('author_label', 100)->nullable();
            $table->text('body');
            $table->timestamps(6);

            $table->foreign('comment_id')
                ->references('id')
                ->on('comments')
                ->cascadeOnDelete();

            $table->index(['comment_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comment_replies');
    }
};
