<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The agent-instruction conventions the Context page knows how to surface.
 *
 * CLAUDE.md / AGENTS.md are matched by basename anywhere in the tree; every
 * other tool keeps arbitrarily-named rule files inside a well-known dot
 * directory, so matching is path-based (see `fromPath()`).
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
     * Pathspecs handed to `git ls-files` to shortlist tracked candidates.
     *
     * Deliberately over-broad (whole dot directories rather than exact file
     * patterns): every shortlisted path is re-checked with `fromPath()`, so a
     * loose pathspec costs a few extra rows while a too-tight one silently
     * loses files. Each entry is listed twice — bare for the repo root and
     * with a leading double-star for nested packages in a monorepo.
     *
     * @return array<int, string>
     */
    public static function gitPathspecs(): array
    {
        $roots = [
            'CLAUDE.md',
            'AGENTS.md',
            '.cursorrules',
            '.windsurfrules',
            '.clinerules',
            '.clinerules/**',
            '.cursor/rules/**',
            '.claude/**',
            '.windsurf/rules/**',
            '.github/copilot-instructions.md',
            '.github/instructions/**',
        ];

        return collect($roots)
            ->flatMap(fn (string $root): array => [
                ':(glob)'.$root,
                ':(glob)**/'.$root,
            ])
            ->all();
    }

    /**
     * Classify a repo-relative path, or null when it is not an agent-context
     * file. Order matters only in that basename rules are checked first —
     * a CLAUDE.md inside `.cursor/rules/` is still a CLAUDE.md.
     */
    public static function fromPath(string $relPath): ?self
    {
        $basename = basename($relPath);

        return match (true) {
            $basename === 'CLAUDE.md' => self::Claude,
            $basename === 'AGENTS.md' => self::Agents,
            $basename === '.cursorrules' => self::Cursor,
            $basename === '.windsurfrules' => self::Windsurf,
            $basename === '.clinerules' => self::Cline,

            self::isMarkdownUnder($relPath, '.claude/agents') => self::Claude,
            self::isMarkdownUnder($relPath, '.claude/commands') => self::Claude,
            self::isMarkdownUnder($relPath, '.claude/rules') => self::Claude,
            $basename === 'SKILL.md' && self::isUnder($relPath, '.claude/skills') => self::Claude,

            self::isMarkdownUnder($relPath, '.cursor/rules') => self::Cursor,
            self::isMarkdownUnder($relPath, '.windsurf/rules') => self::Windsurf,
            self::isMarkdownUnder($relPath, '.clinerules') => self::Cline,
            self::isMarkdownUnder($relPath, '.github/instructions') => self::Copilot,
            $basename === 'copilot-instructions.md' && self::isUnder($relPath, '.github') => self::Copilot,

            default => null,
        };
    }

    /** Human-readable name, used as the badge tooltip in the context tree. */
    public function label(): string
    {
        return match ($this) {
            self::Claude => 'Claude',
            self::Agents => 'Agents',
            self::Cursor => 'Cursor',
            self::Copilot => 'Copilot',
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

    /**
     * True when $relPath sits anywhere below $directory — matching both a
     * repo-root `.cursor/rules/x.mdc` and a monorepo `packages/web/.cursor/rules/x.mdc`.
     */
    private static function isUnder(string $relPath, string $directory): bool
    {
        return str_contains('/'.$relPath, '/'.$directory.'/');
    }

    private static function isMarkdownUnder(string $relPath, string $directory): bool
    {
        if (! self::isUnder($relPath, $directory)) {
            return false;
        }

        return str_ends_with($relPath, '.md') || str_ends_with($relPath, '.mdc');
    }
}
