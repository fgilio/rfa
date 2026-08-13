<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The agent context file conventions the Context page knows how to surface.
 *
 * CLAUDE.md / AGENTS.md are matched by basename anywhere in the tree; every
 * other tool keeps arbitrarily-named rule files inside a well-known dot
 * directory, so matching is path-based.
 */
enum AgentContextFileKind: string
{
    case Claude = 'CLAUDE';
    case Agents = 'AGENTS';
    case Cursor = 'CURSOR';
    case Copilot = 'COPILOT';
    case Windsurf = 'WINDSURF';
    case Cline = 'CLINE';

    /**
     * Every convention we recognise, as `[kind, directory, basename]`.
     *
     * `basename` is an fnmatch pattern, or null for "any markdown file".
     * `directory` is the dot-directory the file must live below, or null to
     * match by basename anywhere in the tree.
     *
     * Order matters only in that directory-less rules come first: a CLAUDE.md
     * inside `.cursor/rules/` is still a CLAUDE.md.
     *
     * @var array<int, array{0: self, 1: ?string, 2: ?string}>
     */
    private const RULES = [
        [self::Claude, null, 'CLAUDE.md'],
        [self::Agents, null, 'AGENTS.md'],
        [self::Cursor, null, '.cursorrules'],
        [self::Windsurf, null, '.windsurfrules'],
        [self::Cline, null, '.clinerules'],

        [self::Claude, '.claude/agents', null],
        [self::Claude, '.claude/commands', null],
        [self::Claude, '.claude/rules', null],
        [self::Claude, '.claude/skills', 'SKILL.md'],

        [self::Cursor, '.cursor/rules', null],
        [self::Windsurf, '.windsurf/rules', null],
        [self::Cline, '.clinerules', null],
        // Copilot only applies the `.instructions.md` files here; a README.md
        // alongside them is documentation, not context.
        [self::Copilot, '.github/instructions', '*.instructions.md'],
        [self::Copilot, '.github', 'copilot-instructions.md'],
    ];

    /**
     * Pathspecs handed to `git ls-files` to shortlist tracked candidates.
     *
     * One pathspec per rule, each prefixed with a leading double-star so it
     * matches both the repo root and any nested package in a monorepo (git
     * treats a leading double-star as zero or more leading directories).
     * Directory rules stay broader than the rule itself — they match any file
     * below, not just markdown — because every shortlisted path is re-checked
     * with `fromPath()`.
     *
     * @return array<int, string>
     */
    public static function gitPathspecs(): array
    {
        return collect(self::RULES)
            ->map(function (array $rule): string {
                [, $directory, $basename] = $rule;

                $pattern = $directory === null
                    ? (string) $basename
                    : $directory.'/**'.($basename === null ? '' : '/'.$basename);

                return ':(glob)**/'.$pattern;
            })
            ->all();
    }

    /**
     * Classify a repo-relative path, or null when it is not an agent-context file.
     */
    public static function fromPath(string $relPath): ?self
    {
        $haystack = '/'.$relPath;

        // `.mdc` is Cursor's rule-file extension.
        $hasRuleExtension = str_ends_with($relPath, '.md') || str_ends_with($relPath, '.mdc');

        // Bail on ordinary source files before the rule scan: every rule needs
        // a rule extension or a dot-prefixed segment. Hot path — fromPath()
        // runs for every entry of the working-tree walk.
        if (! $hasRuleExtension && ! str_contains($haystack, '/.')) {
            return null;
        }

        $basename = basename($relPath);

        foreach (self::RULES as [$kind, $directory, $pattern]) {
            $matchesBasename = $pattern === null
                ? $hasRuleExtension
                : fnmatch($pattern, $basename);

            if (! $matchesBasename) {
                continue;
            }

            if ($directory !== null && ! str_contains($haystack, '/'.$directory.'/')) {
                continue;
            }

            return $kind;
        }

        return null;
    }

    /** Human-readable name of the convention this file belongs to. */
    public function label(): string
    {
        return match ($this) {
            self::Claude => 'Claude Code',
            self::Agents => 'AGENTS.md',
            self::Cursor => 'Cursor',
            self::Copilot => 'GitHub Copilot',
            self::Windsurf => 'Windsurf',
            self::Cline => 'Cline',
        };
    }

    /** Single-letter sigil rendered in the context-tree sidebar. */
    public function badgeLabel(): string
    {
        return match ($this) {
            self::Claude => 'C',
            self::Agents => 'A',
            self::Cursor => 'U',
            self::Copilot => 'P',
            self::Windsurf => 'W',
            self::Cline => 'L',
        };
    }

    /** Tailwind text color class for the sidebar badge. */
    public function badgeColorClass(): string
    {
        return match ($this) {
            self::Claude => 'text-gh-kind-claude',
            self::Agents => 'text-gh-kind-agents',
            self::Cursor => 'text-gh-kind-cursor',
            self::Copilot => 'text-gh-kind-copilot',
            self::Windsurf => 'text-gh-kind-windsurf',
            self::Cline => 'text-gh-kind-cline',
        };
    }
}
