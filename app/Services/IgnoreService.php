<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\File;

/**
 * Filters files out of the changed-files list using `.rfaignore` (a
 * gitignore-flavoured file RFA reads itself, since git's own ignore handling
 * never sees it).
 *
 * {@see self::isPathExcluded()} is the only evaluator. Tracked and untracked
 * files run through it alike, so a file's visibility never turns on whether git
 * happens to track it. Git pathspecs are deliberately not used for this: a
 * pathspec can express an exclude but not a `!` re-include, which is exactly
 * where the two would disagree.
 *
 * Supported `.rfaignore` syntax (a practical subset of gitignore):
 * - `# comment` and blank lines are skipped.
 * - `dir/` (trailing slash) matches the directory and everything under it.
 * - `!pattern` re-includes a path an earlier rule excluded (last match wins).
 * - `/anchored` matches only from the repo root; `mid/path` is also anchored.
 * - a bare `name` matches at any depth (basename match).
 * - `*` matches within a path segment, `?` a single char, `**` across segments.
 *
 * A rename is judged on the path the user sees (the new one), so moving a file
 * out of an ignored directory reveals it and moving one in hides it.
 */
class IgnoreService
{
    /** Lockfiles RFA hides from every review, ahead of and immune to `.rfaignore`. */
    public const ALWAYS_EXCLUDE = [
        'package-lock.json',
        'pnpm-lock.yaml',
        'yarn.lock',
        'bun.lock',
        'composer.lock',
    ];

    /** @var array<int, array{regex: string, negated: bool}>|null */
    private ?array $lockfileRules = null;

    /**
     * Whether a repo-relative path is excluded, applying the rules in order with
     * last-match-wins so `!` negations can re-include.
     *
     * Lockfiles are checked first and are not re-includable: they carry no
     * review value at any size, and leaving them negatable would make the one
     * rule set RFA guarantees depend on user input.
     *
     * @param  array<int, array{regex: string, negated: bool}>  $rules  Output of {@see self::rules()}.
     */
    public function isPathExcluded(string $path, array $rules): bool
    {
        if ($this->matchesAny($path, $this->lockfileRules())) {
            return true;
        }

        $excluded = false;

        foreach ($rules as $rule) {
            if (preg_match($rule['regex'], $path) === 1) {
                $excluded = ! $rule['negated'];
            }
        }

        return $excluded;
    }

    /**
     * The repo's `.rfaignore` rules in order, each precompiled to a matcher
     * regex. Lockfiles are not included: {@see self::isPathExcluded()} applies
     * them ahead of these.
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
     * The user's `.rfaignore` lines, with comments and blanks stripped.
     *
     * @return array<int, string>
     */
    private function rawPatterns(string $repoPath): array
    {
        $ignoreFile = $repoPath.'/.rfaignore';

        if (! File::exists($ignoreFile)) {
            return [];
        }

        return collect(explode("\n", File::get($ignoreFile)))
            ->map(fn (string $line): string => trim($line))
            ->reject(fn (string $line): bool => $line === '' || str_starts_with($line, '#'))
            ->values()
            ->all();
    }

    /** @return array<int, array{regex: string, negated: bool}> */
    private function lockfileRules(): array
    {
        return $this->lockfileRules ??= $this->compile(self::ALWAYS_EXCLUDE);
    }

    /** @param array<int, array{regex: string, negated: bool}> $rules */
    private function matchesAny(string $path, array $rules): bool
    {
        return collect($rules)->contains(fn (array $rule): bool => preg_match($rule['regex'], $path) === 1);
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
                    $i++; // consume the second '*'
                    if (($glob[$i + 1] ?? '') === '/') {
                        // `**/` matches zero or more whole path segments, so the
                        // separator stays part of the match: `(?:.*/)?`. Emitting
                        // a bare `.*` and swallowing the slash drops the segment
                        // boundary, letting `**/build` match `prebuild` or
                        // `a/**/b` match `a/xb`.
                        $i++; // consume the '/'
                        $regex .= '(?:.*/)?';
                    } else {
                        $regex .= '.*';
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
