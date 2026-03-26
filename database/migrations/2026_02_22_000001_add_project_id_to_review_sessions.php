<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('review_sessions', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });

        // Backfill: match existing sessions to projects by normalized path
        if (Schema::hasTable('projects')) {
            $sessions = DB::table('review_sessions')->whereNull('project_id')->get();

            foreach ($sessions as $session) {
                // Try to normalize old repo_path to a canonical --show-toplevel
                $repoPath = $session->repo_path;
                $canonical = trim((string) shell_exec(
                    'git -C '.escapeshellarg($repoPath).' rev-parse --show-toplevel 2>/dev/null'
                ));

                if ($canonical !== '') {
                    $canonical = (string) realpath($canonical);
                }

                // Match against registered projects
                $project = DB::table('projects')
                    ->where('path', $canonical ?: $repoPath)
                    ->first();

                if ($project) {
                    DB::table('review_sessions')
                        ->where('id', $session->id)
                        ->update(['project_id' => $project->id]);
                }
            }
        }

        // Add unique index on project_id (nullable, so only non-null values are constrained)
        Schema::table('review_sessions', function (Blueprint $table) {
            $table->unique('project_id');
        });

        // Drop unique constraint on repo_path (keep column for reference)
        Schema::table('review_sessions', function (Blueprint $table) {
            $table->dropUnique(['repo_path']);
        });
    }

    public function down(): void
    {
        Schema::table('review_sessions', function (Blueprint $table) {
            $table->dropUnique(['project_id']);
            $table->dropConstrainedForeignId('project_id');
            $table->string('repo_path')->unique()->change();
        });
    }
};
