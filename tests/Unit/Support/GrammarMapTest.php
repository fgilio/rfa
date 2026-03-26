<?php

use App\Support\GrammarMap;
use Phiki\Grammar\Grammar;

test('resolves common extensions', function (string $file, Grammar $expected) {
    expect(GrammarMap::resolve($file))->toBe($expected);
})->with([
    ['app.php', Grammar::Php],
    ['index.js', Grammar::Javascript],
    ['main.ts', Grammar::Typescript],
    ['style.css', Grammar::Css],
    ['page.html', Grammar::Html],
    ['data.json', Grammar::Json],
    ['config.yaml', Grammar::Yaml],
    ['config.yml', Grammar::Yaml],
    ['script.py', Grammar::Python],
    ['lib.rb', Grammar::Ruby],
    ['main.rs', Grammar::Rust],
    ['main.go', Grammar::Go],
    ['App.tsx', Grammar::Tsx],
    ['App.jsx', Grammar::Jsx],
    ['run.sh', Grammar::Shellscript],
    ['query.sql', Grammar::Sql],
    ['schema.graphql', Grammar::Graphql],
]);

test('resolves compound extensions', function () {
    expect(GrammarMap::resolve('welcome.blade.php'))->toBe(Grammar::Blade)
        ->and(GrammarMap::resolve('layout.blade.php'))->toBe(Grammar::Blade);
});

test('resolves special filenames', function (string $file, Grammar $expected) {
    expect(GrammarMap::resolve($file))->toBe($expected);
})->with([
    ['Dockerfile', Grammar::Docker],
    ['Makefile', Grammar::Make],
    ['Gemfile', Grammar::Ruby],
    ['Rakefile', Grammar::Ruby],
]);

test('returns null for unknown extensions', function () {
    expect(GrammarMap::resolve('data.xyz'))->toBeNull()
        ->and(GrammarMap::resolve('file.unknown'))->toBeNull();
});

test('handles case insensitive filenames', function () {
    expect(GrammarMap::resolve('DOCKERFILE'))->toBe(Grammar::Docker)
        ->and(GrammarMap::resolve('MAKEFILE'))->toBe(Grammar::Make);
});

test('handles paths with directories', function () {
    expect(GrammarMap::resolve('src/app/Models/User.php'))->toBe(Grammar::Php)
        ->and(GrammarMap::resolve('resources/views/home.blade.php'))->toBe(Grammar::Blade);
});
