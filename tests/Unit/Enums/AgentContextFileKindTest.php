<?php

use App\Enums\AgentContextFileKind;
use App\Services\GitProcessService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class);

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

test('every kind has a theme token in both palettes and a CSS variable', function (AgentContextFileKind $kind) {
    $token = Str::after($kind->badgeColorClass(), 'text-gh-');

    expect(config('theme.colors.light'))->toHaveKey($token)
        ->and(config('theme.colors.dark'))->toHaveKey($token)
        // Without the @theme entry Tailwind never emits the utility and the
        // badge silently falls back to inherited color.
        ->and(File::get(resource_path('css/app.css')))->toContain("--color-gh-{$token}:");
})->with(AgentContextFileKind::cases());

test('git pathspecs cover every classified path', function () {
    $repo = $this->createTempDirectory('rfa_ctxspec_');
    $this->initTestRepo($repo);

    $paths = [
        'CLAUDE.md', 'app/Domains/CLAUDE.md', 'AGENTS.md',
        '.claude/agents/reviewer.md', '.claude/commands/ship.md', '.claude/rules/php.md',
        '.claude/skills/release/SKILL.md', '.cursorrules', '.cursor/rules/php.mdc',
        'packages/web/.cursor/rules/vue.mdc', '.github/copilot-instructions.md',
        '.github/instructions/tests.instructions.md', '.windsurfrules',
        '.windsurf/rules/style.md', '.clinerules/style.md',
    ];

    foreach ($paths as $path) {
        File::ensureDirectoryExists($repo.'/'.dirname($path));
        File::put($repo.'/'.$path, "x\n");
    }
    $this->commitTestRepo($repo, 'init');

    $output = app(GitProcessService::class)
        ->run($repo, ['ls-files', '-z', ...AgentContextFileKind::gitPathspecs()]);

    $matched = array_filter(explode("\0", $output));

    expect(array_diff($paths, $matched))->toBeEmpty();
});
