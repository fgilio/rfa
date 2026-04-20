<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The pool-redesign migration dropped the composite unique on
     * `review_sessions(project_id, context_fingerprint)` together with the
     * `context_fingerprint` column, but never re-established a unique key.
     * `reviewed_files_repo_unique` also covers bare-repo and project-scoped rows
     * in one index, which collides once a project is created for an existing
     * bare-repo path. Re-add partial uniques scoped by `project_id IS NULL` so
     * bare-repo and project-scoped rows never share a unique key.
     *
     * SQLite partial indexes (WHERE clause) have been supported since 3.8.
     * RFA ships SQLite via NativePHP; this migration is a no-op on other
     * drivers so environments that use MySQL/Postgres for testing don't fail
     * on the partial-index syntax.
     */
    public function up(): void
    {
        if (! $this->isSqlite()) {
            return;
        }

        if ($this->hasIndex('review_sessions', 'review_sessions_project_id_unique')) {
            Schema::table('review_sessions', function (Blueprint $table) {
                $table->dropUnique('review_sessions_project_id_unique');
            });
        }

        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS review_sessions_project_id_unique '
            .'ON review_sessions (project_id) '
            .'WHERE project_id IS NOT NULL'
        );

        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS review_sessions_bare_repo_unique '
            .'ON review_sessions (repo_path) '
            .'WHERE project_id IS NULL'
        );

        if ($this->hasIndex('reviewed_files', 'reviewed_files_repo_unique')) {
            Schema::table('reviewed_files', function (Blueprint $table) {
                $table->dropUnique('reviewed_files_repo_unique');
            });
        }

        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS reviewed_files_bare_repo_unique '
            .'ON reviewed_files (repo_path, file_path, content_hash) '
            .'WHERE project_id IS NULL'
        );
    }

    public function down(): void
    {
        if (! $this->isSqlite()) {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS review_sessions_project_id_unique');
        DB::statement('DROP INDEX IF EXISTS review_sessions_bare_repo_unique');
        DB::statement('DROP INDEX IF EXISTS reviewed_files_bare_repo_unique');

        // Restore the original (unscoped) repo unique on reviewed_files.
        if (! $this->hasIndex('reviewed_files', 'reviewed_files_repo_unique')) {
            Schema::table('reviewed_files', function (Blueprint $table) {
                $table->unique(['repo_path', 'file_path', 'content_hash'], 'reviewed_files_repo_unique');
            });
        }
    }

    private function isSqlite(): bool
    {
        return DB::connection()->getDriverName() === 'sqlite';
    }

    private function hasIndex(string $table, string $name): bool
    {
        $result = DB::selectOne(
            'SELECT name FROM sqlite_master WHERE type = ? AND name = ?',
            ['index', $name],
        );

        return $result !== null;
    }
};
