<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('review_sessions')->orderBy('id')->lazyById()->each(function (object $session) use ($now): void {
            $this->migrateComments($session, $now);
            $this->migrateReviewedFiles($session, $now);
        });

        // Keep only a single review_sessions row per repo/project for the global_comment.
        $this->dedupeReviewSessions();

        Schema::table('review_sessions', function (Blueprint $table) {
            if (Schema::hasColumn('review_sessions', 'comments')) {
                $table->dropColumn('comments');
            }

            if (Schema::hasColumn('review_sessions', 'reviewed_files')) {
                $table->dropColumn('reviewed_files');
            }
        });

        Schema::table('review_sessions', function (Blueprint $table) {
            if (Schema::hasColumn('review_sessions', 'context_fingerprint')) {
                try {
                    $table->dropUnique(['project_id', 'context_fingerprint']);
                } catch (Throwable) {
                    // Index may not exist on all databases; ignore.
                }
                $table->dropColumn('context_fingerprint');
            }
        });
    }

    public function down(): void
    {
        Schema::table('review_sessions', function (Blueprint $table) {
            $table->string('context_fingerprint')->default('working')->after('project_id');
            $table->json('reviewed_files')->default('[]');
            $table->json('comments')->default('[]');
        });
    }

    private function migrateComments(object $session, Carbon $now): void
    {
        $raw = $session->comments ?? '[]';
        $decoded = is_string($raw) ? json_decode($raw, true) : $raw;

        if (! is_array($decoded) || $decoded === []) {
            return;
        }

        $originRef = $this->resolveOriginRef($session->context_fingerprint ?? 'working');

        foreach ($decoded as $c) {
            if (! is_array($c) || empty($c['id'] ?? null) || empty($c['file'] ?? null)) {
                continue;
            }

            DB::table('comments')->insertOrIgnore([
                'id' => $c['id'],
                'project_id' => $session->project_id ?? null,
                'repo_path' => $session->repo_path ?? '',
                'origin_ref' => $originRef,
                'file_path' => $c['file'],
                'side' => $c['side'] ?? 'right',
                'start_line' => $c['startLine'] ?? null,
                'end_line' => $c['endLine'] ?? null,
                'file_content_hash' => null,
                'line_snippet' => null,
                'body' => $c['body'] ?? '',
                'is_draft' => ! empty($c['isDraft']),
                'submitted_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function migrateReviewedFiles(object $session, Carbon $now): void
    {
        $raw = $session->reviewed_files ?? '[]';
        $decoded = is_string($raw) ? json_decode($raw, true) : $raw;

        if (! is_array($decoded) || $decoded === []) {
            return;
        }

        foreach ($decoded as $path => $hash) {
            if (is_int($path)) {
                // Legacy indexed array of paths.
                $filePath = (string) $hash;
                $contentHash = '';
            } else {
                $filePath = (string) $path;
                $contentHash = (string) ($hash ?? '');
            }

            if ($filePath === '') {
                continue;
            }

            DB::table('reviewed_files')->insertOrIgnore([
                'project_id' => $session->project_id ?? null,
                'repo_path' => $session->repo_path ?? '',
                'file_path' => $filePath,
                'content_hash' => $contentHash,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function resolveOriginRef(string $fingerprint): string
    {
        if ($fingerprint === '' || $fingerprint === 'working') {
            return 'working';
        }

        if (str_contains($fingerprint, '..')) {
            [, $to] = explode('..', $fingerprint, 2);

            return $to !== '' ? $to : 'working';
        }

        return $fingerprint;
    }

    private function dedupeReviewSessions(): void
    {
        // For each project_id/repo_path, keep the most recently updated row.
        // `updated_at DESC, id DESC` reflects user intent better than longest-wins
        // (which could discard a freshly edited shorter note in favor of stale text).
        $groups = DB::table('review_sessions')
            ->selectRaw('COALESCE(project_id, 0) as group_key, project_id, repo_path')
            ->groupBy('group_key', 'project_id', 'repo_path')
            ->get();

        foreach ($groups as $group) {
            $query = DB::table('review_sessions')->where('repo_path', $group->repo_path);
            $query = $group->project_id
                ? $query->where('project_id', $group->project_id)
                : $query->whereNull('project_id');

            $rows = $query->orderByDesc('updated_at')->orderByDesc('id')->get();
            if ($rows->count() <= 1) {
                continue;
            }

            $keeper = $rows->first();
            $idsToDelete = $rows->pluck('id')->reject(fn ($id) => $id === $keeper->id)->all();

            if ($idsToDelete !== []) {
                DB::table('review_sessions')->whereIn('id', $idsToDelete)->delete();
            }
        }
    }
};
