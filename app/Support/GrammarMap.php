<?php

declare(strict_types=1);

namespace App\Support;

use Phiki\Grammar\Grammar;

final class GrammarMap
{
    /** @var array<string, Grammar> */
    private const FILENAME_MAP = [
        'dockerfile' => Grammar::Docker,
        'makefile' => Grammar::Make,
        'rakefile' => Grammar::Ruby,
        'gemfile' => Grammar::Ruby,
        'vagrantfile' => Grammar::Ruby,
        'procfile' => Grammar::Shellscript,
    ];

    /** @var array<string, Grammar> */
    private const COMPOUND_MAP = [
        'blade.php' => Grammar::Blade,
        'vue.html' => Grammar::Vue,
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
        'html' => Grammar::Html,
        'htm' => Grammar::Html,
        'xml' => Grammar::Xml,
        'svg' => Grammar::Xml,
        'json' => Grammar::Json,
        'jsonc' => Grammar::Jsonc,
        'yaml' => Grammar::Yaml,
        'yml' => Grammar::Yaml,
        'toml' => Grammar::Toml,
        'ini' => Grammar::Ini,
        'env' => Grammar::Dotenv,
        'md' => Grammar::Markdown,
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
        'ps1' => Grammar::Powershell,
        'sql' => Grammar::Sql,
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
        'docker' => Grammar::Docker,
        'nix' => Grammar::Nix,
        'zig' => Grammar::Zig,
        'dart' => Grammar::Dart,
        'groovy' => Grammar::Groovy,
        'perl' => Grammar::Perl,
        'pl' => Grammar::Perl,
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

        // Check compound extensions (e.g. blade.php)
        foreach (self::COMPOUND_MAP as $compound => $grammar) {
            if (str_ends_with($filename, '.'.$compound)) {
                return $grammar;
            }
        }

        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        return self::EXTENSION_MAP[$ext] ?? null;
    }
}
