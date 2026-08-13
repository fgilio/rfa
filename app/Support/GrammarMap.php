<?php

declare(strict_types=1);

namespace App\Support;

use Phiki\Grammar\Grammar;

final class GrammarMap
{
    /** @var array<string, Grammar> */
    private const FILENAME_MAP = [
        '.babelrc' => Grammar::Json,
        '.bashrc' => Grammar::Shellscript,
        '.clinerules' => Grammar::Markdown,
        '.cursorrules' => Grammar::Markdown,
        '.gitmodules' => Grammar::Ini,
        '.htaccess' => Grammar::Apache,
        '.npmrc' => Grammar::Ini,
        '.prettierrc' => Grammar::Json,
        '.shiftrc' => Grammar::Ini,
        '.watchmanconfig' => Grammar::Json,
        '.windsurfrules' => Grammar::Markdown,
        'dockerfile' => Grammar::Docker,
        'dockerfile.gs-build' => Grammar::Docker,
        'ios-fonts.template' => Grammar::Xml,
        'makefile' => Grammar::Make,
        'rakefile' => Grammar::Ruby,
        'gemfile' => Grammar::Ruby,
        'vagrantfile' => Grammar::Ruby,
        'fastfile' => Grammar::Ruby,
        'podfile' => Grammar::Ruby,
        'procfile' => Grammar::Shellscript,
        'gradlew' => Grammar::Shellscript,
    ];

    /** @var array<string, Grammar> */
    private const COMPOUND_MAP = [
        'blade.php' => Grammar::Blade,
        'vue.html' => Grammar::Vue,
        'html.template' => Grammar::Html,
        'js.template' => Grammar::Javascript,
        'json.example' => Grammar::Json,
        'plist.template' => Grammar::Xml,
        'properties.template' => Grammar::Ini,
        'entitlements.template' => Grammar::Xml,
        'xcscheme.template' => Grammar::Xml,
        'xml.template' => Grammar::Xml,
        'xml.dist' => Grammar::Xml,
        'toml.example' => Grammar::Toml,
        'env.example' => Grammar::Dotenv,
    ];

    /** @var array<string, Grammar> */
    private const EXTENSION_MAP = [
        'php' => Grammar::Php,
        'js' => Grammar::Javascript,
        'cjs' => Grammar::Javascript,
        'mjs' => Grammar::Javascript,
        'ts' => Grammar::Typescript,
        'cts' => Grammar::Typescript,
        'mts' => Grammar::Typescript,
        'jsx' => Grammar::Jsx,
        'tsx' => Grammar::Tsx,
        'vue' => Grammar::Vue,
        'svelte' => Grammar::Svelte,
        'css' => Grammar::Css,
        'scss' => Grammar::Scss,
        'sass' => Grammar::Sass,
        'less' => Grammar::Less,
        'ejs' => Grammar::Html,
        'html' => Grammar::Html,
        'htm' => Grammar::Html,
        'xhtml' => Grammar::Xml,
        'xml' => Grammar::Xml,
        'opf' => Grammar::Xml,
        'ncx' => Grammar::Xml,
        'xsl' => Grammar::Xml,
        'plist' => Grammar::Xml,
        'storyboard' => Grammar::Xml,
        'xcscheme' => Grammar::Xml,
        'xcworkspacedata' => Grammar::Xml,
        'entitlements' => Grammar::Xml,
        'xcprivacy' => Grammar::Xml,
        'config' => Grammar::Xml,
        'svg' => Grammar::Xml,
        'json' => Grammar::Json,
        'jsonc' => Grammar::Jsonc,
        'jsonl' => Grammar::Jsonl,
        'webmanifest' => Grammar::Json,
        'code-workspace' => Grammar::Json,
        'yaml' => Grammar::Yaml,
        'yml' => Grammar::Yaml,
        'toml' => Grammar::Toml,
        'ini' => Grammar::Ini,
        'properties' => Grammar::Ini,
        'editorconfig' => Grammar::Ini,
        'env' => Grammar::Dotenv,
        'neon' => Grammar::Neon,
        'stub' => Grammar::Php,
        'snap' => Grammar::Javascript,
        'md' => Grammar::Markdown,
        'mdc' => Grammar::Mdc,
        'mdx' => Grammar::Mdx,
        'py' => Grammar::Python,
        'rb' => Grammar::Ruby,
        'rs' => Grammar::Rust,
        'go' => Grammar::Go,
        'java' => Grammar::Java,
        'kt' => Grammar::Kotlin,
        'kts' => Grammar::Kotlin,
        'swift' => Grammar::Swift,
        'c' => Grammar::C,
        'h' => Grammar::C,
        'cpp' => Grammar::Cpp,
        'hpp' => Grammar::Cpp,
        'cs' => Grammar::Csharp,
        'sh' => Grammar::Shellscript,
        'bash' => Grammar::Shellscript,
        'zsh' => Grammar::Shellscript,
        'fish' => Grammar::Fish,
        'bat' => Grammar::Bat,
        'bats' => Grammar::Shellscript,
        'ps1' => Grammar::Powershell,
        'sql' => Grammar::Sql,
        'dump' => Grammar::Sql,
        'graphql' => Grammar::Graphql,
        'gql' => Grammar::Graphql,
        'lua' => Grammar::Lua,
        'r' => Grammar::R,
        'ex' => Grammar::Elixir,
        'exs' => Grammar::Elixir,
        'erl' => Grammar::Erlang,
        'hs' => Grammar::Haskell,
        'clj' => Grammar::Clojure,
        'scala' => Grammar::Scala,
        'tf' => Grammar::Terraform,
        'hcl' => Grammar::Terraform,
        'gradle' => Grammar::Groovy,
        'docker' => Grammar::Docker,
        'dockerfile' => Grammar::Docker,
        'nix' => Grammar::Nix,
        'zig' => Grammar::Zig,
        'dart' => Grammar::Dart,
        'groovy' => Grammar::Groovy,
        'perl' => Grammar::Perl,
        'pl' => Grammar::Perl,
        'm' => Grammar::ObjectiveC,
        'mm' => Grammar::ObjectiveCpp,
        'csv' => Grammar::Csv,
        'diff' => Grammar::Diff,
        'log' => Grammar::Log,
        'nginx' => Grammar::Nginx,
        'conf' => Grammar::Nginx,
        'twig' => Grammar::Twig,
        'astro' => Grammar::Astro,
    ];

    public static function resolve(string $filePath): ?Grammar
    {
        $filename = strtolower(basename($filePath));

        if (isset(self::FILENAME_MAP[$filename])) {
            return self::FILENAME_MAP[$filename];
        }

        if (str_starts_with($filename, '.env.') || str_starts_with($filename, '.dev.vars')) {
            return Grammar::Dotenv;
        }

        // Check compound extensions (e.g. blade.php).
        foreach (self::COMPOUND_MAP as $compound => $grammar) {
            if (str_ends_with($filename, '.'.$compound)) {
                return $grammar;
            }
        }

        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        return self::EXTENSION_MAP[$ext] ?? null;
    }
}
