<?php

use App\Support\MarkdownPath;

test('detects markdown paths', function (string $path) {
    expect(MarkdownPath::isMarkdown($path))->toBeTrue();
})->with([
    'md' => ['CLAUDE.md'],
    'nested md' => ['app/Domains/AGENTS.md'],
    'uppercase extension' => ['README.MD'],
    'mdx' => ['docs/intro.mdx'],
    'markdown' => ['notes.markdown'],
    // Agent rule files: extension-bearing and bare alike, so the Context page
    // gives them the same heading folding and table alignment as a CLAUDE.md.
    'cursor rule' => ['.cursor/rules/php.mdc'],
    'cursorrules' => ['.cursorrules'],
    'windsurfrules' => ['.windsurfrules'],
    'clinerules' => ['.clinerules'],
    'nested cursorrules' => ['packages/web/.cursorrules'],
]);

test('rejects non-markdown paths', function (string $path) {
    expect(MarkdownPath::isMarkdown($path))->toBeFalse();
})->with([
    'php' => ['app/Models/Comment.php'],
    'json' => ['.cursor/rules/schema.json'],
    'no extension' => ['Makefile'],
    'rules dir is not a rule file' => ['.clinerules/style.txt'],
]);
