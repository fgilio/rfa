<?php

use App\Enums\AgentContextFileKind;

test('classifies known agent-context paths', function (string $path, AgentContextFileKind $expected) {
    expect(AgentContextFileKind::fromPath($path))->toBe($expected);
})->with([
    ['CLAUDE.md', AgentContextFileKind::Claude],
    ['app/Domains/Reader/CLAUDE.md', AgentContextFileKind::Claude],
    ['AGENTS.md', AgentContextFileKind::Agents],
    ['.claude/agents/reviewer.md', AgentContextFileKind::Claude],
    ['.claude/commands/ship.md', AgentContextFileKind::Claude],
    ['.claude/rules/php.md', AgentContextFileKind::Claude],
    ['.claude/skills/release/SKILL.md', AgentContextFileKind::Claude],
    ['.cursorrules', AgentContextFileKind::Cursor],
    ['.cursor/rules/php.mdc', AgentContextFileKind::Cursor],
    ['packages/web/.cursor/rules/vue.mdc', AgentContextFileKind::Cursor],
    ['.github/copilot-instructions.md', AgentContextFileKind::Copilot],
    ['.github/instructions/tests.instructions.md', AgentContextFileKind::Copilot],
    ['.windsurfrules', AgentContextFileKind::Windsurf],
    ['.windsurf/rules/style.md', AgentContextFileKind::Windsurf],
    ['.clinerules', AgentContextFileKind::Cline],
    ['.clinerules/style.md', AgentContextFileKind::Cline],
]);

test('rejects impostors and non-rule neighbours', function (string $path) {
    expect(AgentContextFileKind::fromPath($path))->toBeNull();
})->with([
    'basename prefix' => ['MY_CLAUDE.md'],
    'basename suffix' => ['CLAUDE.md.bak'],
    'cursorrules lookalike' => ['.cursorrules-backup'],
    'dot dir lookalike' => ['vendor/foo.claude/agents/x.md'],
    'plans are not instructions' => ['.claude/plans/some-plan.md'],
    'settings are not instructions' => ['.claude/settings.json'],
    'skill support files' => ['.claude/skills/release/reference.md'],
    'non-markdown in a rules dir' => ['.cursor/rules/schema.json'],
    'copilot instructions outside .github' => ['docs/copilot-instructions.md'],
]);

test('badge labels and colors are unique per kind', function () {
    $kinds = AgentContextFileKind::cases();

    expect(collect($kinds)->map(fn ($kind) => $kind->badgeLabel())->unique())->toHaveCount(count($kinds));
    expect(collect($kinds)->map(fn ($kind) => $kind->badgeColorClass())->unique())->toHaveCount(count($kinds));
});
