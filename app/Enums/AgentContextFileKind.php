<?php

declare(strict_types=1);

namespace App\Enums;

enum AgentContextFileKind: string
{
    case Claude = 'CLAUDE';
    case Agents = 'AGENTS';

    public static function fromBasename(string $basename): ?self
    {
        return match ($basename) {
            'CLAUDE.md' => self::Claude,
            'AGENTS.md' => self::Agents,
            default => null,
        };
    }

    public function basename(): string
    {
        return $this->value.'.md';
    }

    /** Single-letter sigil rendered in the context-tree sidebar. */
    public function badgeLabel(): string
    {
        return $this->value[0];
    }

    /** Tailwind text color class for the sidebar badge. */
    public function badgeColorClass(): string
    {
        return match ($this) {
            self::Claude => 'text-gh-kind-claude',
            self::Agents => 'text-gh-kind-agents',
        };
    }
}
