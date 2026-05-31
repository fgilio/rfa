<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\File;

/**
 * Filters files out of the changed-files list using `.rfaignore` — a
 * gitignore-flavoured file RFA reads itself (git's own ignore handling never
 * sees it). Tracked changes are pre-filtered by git via {@see self::getExcludePathspecs()};
 * untracked files are filtered in PHP via {@see self::isPathExcluded()}, which
 * is where the gitignore semantics below live.
 *
 * Supported `.rfaignore` syntax (a practical subset of gitignore):
 * - `# comment` and blank lines are skipped.
 * - `dir/` (trailing slash) matches the directory and everything under it.
 * - `!pattern` re-includes a path an earlier rule excluded (last match wins).
 * - `/anchored` matches only from the repo root; `mid/path` is also anchored.
 * - a bare `name` matches at any depth (basename match).
 * - `*` matches within a path segment, `?` a single char, `**` across segments.
 *
 * Note: `!` re-inclusion is honoured for untracked files (the PHP path). Tracked
 * files are filtered by git pathspecs, which can express the exclude but not the
 * re-include, so a `!` rule does not resurrect a tracked file an exclude hid.
 */
class IgnoreService
{
    private const ALWAYS_EXCLUDE = [
        'package-lock.json',
        'pnpm-lock.yaml',
        'yarn.lock',
        'bun.lock',
        'composer.lock',
    ];

    /**
     * Whether a repo-relative path is excluded, applying the rules in order with
     * last-match-wins so `!` negations can re-include.
     *
     * @param  array<int, array{regex: string, negated: bool}>  $rules  Output of {@see self::rules()}.
     */
    public function isPathExcluded(string $path, array $rules): bool
    {
        $excluded = false;

        foreach ($rules as $rule) {
            if (preg_match($rule['regex'], $path) === 1) {
                $excluded = ! $rule['negated'];
            }
        }

        return $excluded;
    }

    /**
     * Parse the effective ignore rules for a repo (always-excluded lockfiles plus
     * `.rfaignore`), in order, each precompiled to a matcher regex.
     *
     * @return array<int, array{regex: string, negated: bool}>
     */
    public function rules(string $repoPath): array
    {
        return $this->compile($this->rawPatterns($repoPath));
    }

    /**
     * Compile raw ignore patterns into ordered matcher rules.
     *
     * @param  array<int, string>  $patterns
     * @return array<int, array{regex: string, negated: bool}>
     */
    public function compile(array $patterns): array
    {
        return collect($patterns)
            ->map(fn (string $pattern): ?array => $this->compileRule($pattern))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Git pathspecs that exclude the same files for tracked-change commands.
     * Negation (`!`) lines are skipped — a pathspec can exclude but not re-include,
     * and emitting one would wrongly exclude a file literally named like the rule.
     *
     * @return array<int, string>
     */
    public function getExcludePathspecs(string $repoPath): array
    {
        return collect($this->rawPatterns($repoPath))
            ->reject(fn (string $pattern): bool => str_starts_with($pattern, '!'))
            ->map(function (string $pattern): string {
                $needsGlob = ! str_contains($pattern, '/') && ! str_contains($pattern, '*');

                return $needsGlob
                    ? ":(glob,exclude)**/{$pattern}"
                    : ":(exclude){$pattern}";
            })
            ->values()
            ->all();
    }

    /**
     * Raw ignore lines: the always-excluded lockfiles followed by the user's
     * `.rfaignore`, with comments and blanks stripped.
     *
     * @return array<int, string>
     */
    private function rawPatterns(string $repoPath): array
    {
        $patterns = self::ALWAYS_EXCLUDE;

        $ignoreFile = $repoPath.'/.rfaignore';
        if (File::exists($ignoreFile)) {
            $patterns = array_merge(
                $patterns,
                collect(explode("\n", File::get($ignoreFile)))
                    ->map(fn (string $line): string => trim($line))
                    ->reject(fn (string $line): bool => $line === '' || str_starts_with($line, '#'))
                    ->values()
                    ->all()
            );
        }

        return $patterns;
    }

    /**
     * Compile a single `.rfaignore` pattern into a matcher rule, or null when the
     * line carries no pattern (e.g. a bare `!`).
     *
     * @return array{regex: string, negated: bool}|null
     */
    private function compileRule(string $pattern): ?array
    {
        $negated = str_starts_with($pattern, '!');
        if ($negated) {
            $pattern = substr($pattern, 1);
        }

        $dirOnly = str_ends_with($pattern, '/');
        $pattern = rtrim($pattern, '/');

        $anchored = false;
        if (str_starts_with($pattern, '/')) {
            $anchored = true;
            $pattern = ltrim($pattern, '/');
        } elseif (str_contains($pattern, '/')) {
            $anchored = true;
        }

        if ($pattern === '') {
            return null;
        }

        // Prefix: anchored patterns match from the root; bare-name patterns match
        // at any depth. Suffix: match the node itself and everything under it, so
        // a directory rule (or a name that is a directory) also hides its contents.
        $prefix = $anchored ? '^' : '^(?:.*/)?';
        $body = $this->globToRegex($pattern);

        return [
            'regex' => '#'.$prefix.$body.'(?:/.*)?$#',
            'negated' => $negated,
        ];
    }

    /**
     * Convert a glob to a regex body. `**` spans path segments; `*` and `?` stay
     * within a single segment; everything else is matched literally.
     */
    private function globToRegex(string $glob): string
    {
        $regex = '';
        $length = strlen($glob);

        for ($i = 0; $i < $length; $i++) {
            $char = $glob[$i];

            if ($char === '*') {
                if (($glob[$i + 1] ?? '') === '*') {
                    $regex .= '.*';
                    $i++;
                    if (($glob[$i + 1] ?? '') === '/') {
                        $i++;
                    }
                } else {
                    $regex .= '[^/]*';
                }
            } elseif ($char === '?') {
                $regex .= '[^/]';
            } else {
                $regex .= preg_quote($char, '#');
            }
        }

        return $regex;
    }
}
