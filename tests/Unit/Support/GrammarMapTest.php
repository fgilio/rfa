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
        ->and(GrammarMap::resolve('layout.blade.php'))->toBe(Grammar::Blade)
        ->and(GrammarMap::resolve('bundle-index.html.template'))->toBe(Grammar::Html)
        ->and(GrammarMap::resolve('config.js.template'))->toBe(Grammar::Javascript)
        ->and(GrammarMap::resolve('urls.json.example'))->toBe(Grammar::Json)
        ->and(GrammarMap::resolve('Info.plist.template'))->toBe(Grammar::Xml)
        ->and(GrammarMap::resolve('gradle.properties.template'))->toBe(Grammar::Ini)
        ->and(GrammarMap::resolve('PublicaMobile.entitlements.template'))->toBe(Grammar::Xml)
        ->and(GrammarMap::resolve('Staging.xcscheme.template'))->toBe(Grammar::Xml)
        ->and(GrammarMap::resolve('strings.xml.template'))->toBe(Grammar::Xml)
        ->and(GrammarMap::resolve('phpunit.xml.dist'))->toBe(Grammar::Xml)
        ->and(GrammarMap::resolve('wrangler.toml.example'))->toBe(Grammar::Toml)
        ->and(GrammarMap::resolve('.env.example'))->toBe(Grammar::Dotenv);
});

test('resolves special filenames', function (string $file, Grammar $expected) {
    expect(GrammarMap::resolve($file))->toBe($expected);
})->with([
    ['Dockerfile', Grammar::Docker],
    ['Dockerfile.gs-build', Grammar::Docker],
    ['Makefile', Grammar::Make],
    ['Gemfile', Grammar::Ruby],
    ['Rakefile', Grammar::Ruby],
    ['Fastfile', Grammar::Ruby],
    ['Podfile', Grammar::Ruby],
    ['gradlew', Grammar::Shellscript],
    ['.babelrc', Grammar::Json],
    ['.bashrc', Grammar::Shellscript],
    ['.cursorrules', Grammar::Markdown],
    ['.gitmodules', Grammar::Ini],
    ['.prettierrc', Grammar::Json],
    ['.watchmanconfig', Grammar::Json],
    ['.npmrc', Grammar::Ini],
    ['.shiftrc', Grammar::Ini],
    ['.htaccess', Grammar::Apache],
    ['.env.production', Grammar::Dotenv],
    ['.env.testing', Grammar::Dotenv],
    ['.dev.vars', Grammar::Dotenv],
    ['.dev.vars.local', Grammar::Dotenv],
]);

test('resolves project-specific formats found under pla', function (string $file, Grammar $expected) {
    expect(GrammarMap::resolve($file))->toBe($expected);
})->with([
    ['chapter.xhtml', Grammar::Xml],
    ['content.opf', Grammar::Xml],
    ['toc.ncx', Grammar::Xml],
    ['sitemap.xsl', Grammar::Xml],
    ['PrivacyInfo.xcprivacy', Grammar::Xml],
    ['Dev.xcscheme', Grammar::Xml],
    ['ios-fonts.template', Grammar::Xml],
    ['PublicaMobile.entitlements', Grammar::Xml],
    ['LaunchScreen.storyboard', Grammar::Xml],
    ['contents.xcworkspacedata', Grammar::Xml],
    ['web.config', Grammar::Xml],
    ['site.webmanifest', Grammar::Json],
    ['pla.code-workspace', Grammar::Json],
    ['phpstan.neon', Grammar::Neon],
    ['gradle-wrapper.properties', Grammar::Ini],
    ['settings.gradle', Grammar::Groovy],
    ['production.Dockerfile', Grammar::Docker],
    ['gradlew.bat', Grammar::Bat],
    ['network.bats', Grammar::Shellscript],
    ['main.m', Grammar::ObjectiveC],
    ['AppDelegate.mm', Grammar::ObjectiveCpp],
    ['template.ejs', Grammar::Html],
    ['rules.mdc', Grammar::Mdc],
    ['audit.jsonl', Grammar::Jsonl],
    ['summaryGenerator.test.ts.snap', Grammar::Javascript],
    ['migration.create.stub', Grammar::Php],
    ['singlestore-schema.dump', Grammar::Sql],
    ['actions.csv', Grammar::Csv],
    ['default.editorconfig', Grammar::Ini],
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
