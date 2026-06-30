<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\DiffLine;
use App\DTOs\Hunk;
use App\Enums\LineType;
use App\Support\GrammarMap;
use Illuminate\Support\Facades\Log;
use Phiki\Grammar\Grammar;
use Phiki\Phiki;
use Phiki\Theme\ParsedTheme;
use Phiki\Theme\Theme;
use Tempest\Highlight\Highlighter as TempestHighlighter;
use Throwable;

use function e;

// Performance notes
//
// Large "Show full file" diffs are sensitive to syntax highlighting time.
// The Blade regression fixture from b0456e... has ~2,200 rendered lines:
// Phiki took roughly 3s end-to-end, while Tempest keeps the highlighted path
// around 70ms in the benchmark runner.
//
// Tempest is the primary highlighter for common review targets (Blade, PHP,
// JS/TS, CSS, JSON, YAML, Markdown, diff). Blade files need extra handling:
// Livewire SFCs start with a raw PHP opening/closing block, while Tempest's
// Blade language only injects PHP for Blade constructs (`@php`, `{{ }}`, `@if`).
// The adapter segments raw PHP blocks and highlights them with PHP, then uses
// Blade for the template regions.
//
// Phiki remains as the compatibility fallback for languages Tempest does not
// cover. The fallback still uses Phiki's token API and cached scope-to-class
// matching; avoid `codeToHtml()` because it allocates a heavier DOM model.
//
// Diff lines are highlighted by reconstructing old-side and new-side code for
// each hunk, then mapping highlighted HTML back to the original DiffLine
// objects. A highlighter result is only accepted when its line count exactly
// matches the input line count. This prevents broken row alignment in split and
// unified views.
//
// Default-context hunks may start inside a Livewire SFC PHP block without the
// opening tag. In that case the Blade adapter uses a conservative PHP-shape
// heuristic so method bodies are not sent through the HTML-oriented Blade lexer.
//
// Do not batch separate hunks together. Missing inter-hunk context would let
// tokenizer state bleed across unrelated regions and produce misleading spans.
class SyntaxHighlightService
{
    /** @var array<string, string> */
    private const TEMPEST_FILENAME_MAP = [
        '.babelrc' => 'json',
        '.bashrc' => 'bash',
        '.cursorrules' => 'markdown',
        '.gitmodules' => 'ini',
        '.npmrc' => 'ini',
        '.prettierrc' => 'json',
        '.shiftrc' => 'ini',
        '.watchmanconfig' => 'json',
        'dockerfile' => 'dockerfile',
        'dockerfile.gs-build' => 'dockerfile',
        'ios-fonts.template' => 'xml',
        'makefile' => 'text',
    ];

    /** @var array<string, string> */
    private const TEMPEST_COMPOUND_MAP = [
        'blade.php' => 'blade',
        'html.template' => 'html',
        'js.template' => 'javascript',
        'json.example' => 'json',
        'plist.template' => 'xml',
        'properties.template' => 'ini',
        'entitlements.template' => 'xml',
        'xcscheme.template' => 'xml',
        'xml.template' => 'xml',
        'xml.dist' => 'xml',
        'toml.example' => 'text',
        'env.example' => 'dotenv',
    ];

    /** @var array<string, string> */
    private const TEMPEST_EXTENSION_MAP = [
        'php' => 'php',
        'js' => 'javascript',
        'cjs' => 'javascript',
        'mjs' => 'javascript',
        'ts' => 'typescript',
        'cts' => 'typescript',
        'mts' => 'typescript',
        'jsx' => 'javascript',
        'tsx' => 'typescript',
        'svelte' => 'svelte',
        'css' => 'css',
        'scss' => 'scss',
        'ejs' => 'html',
        'html' => 'html',
        'htm' => 'html',
        'xhtml' => 'xml',
        'xml' => 'xml',
        'opf' => 'xml',
        'ncx' => 'xml',
        'xsl' => 'xml',
        'plist' => 'xml',
        'storyboard' => 'xml',
        'xcscheme' => 'xml',
        'xcworkspacedata' => 'xml',
        'entitlements' => 'xml',
        'xcprivacy' => 'xml',
        'config' => 'xml',
        'svg' => 'xml',
        'json' => 'json',
        'jsonl' => 'json',
        'webmanifest' => 'json',
        'code-workspace' => 'json',
        'yaml' => 'yaml',
        'yml' => 'yaml',
        'ini' => 'ini',
        'properties' => 'ini',
        'editorconfig' => 'ini',
        'env' => 'dotenv',
        'stub' => 'php',
        'md' => 'markdown',
        'mdc' => 'markdown',
        'py' => 'python',
        'sh' => 'bash',
        'bash' => 'bash',
        'zsh' => 'bash',
        'sql' => 'sql',
        'dump' => 'sql',
        'graphql' => 'graphql',
        'gql' => 'graphql',
        'tf' => 'terraform',
        'hcl' => 'terraform',
        'docker' => 'dockerfile',
        'dockerfile' => 'dockerfile',
        'diff' => 'diff',
        'patch' => 'diff',
        'nginx' => 'nginx',
        'conf' => 'nginx',
        'twig' => 'twig',
    ];

    /** @var array<string, array{light: string, dark: string}> */
    private const TEMPEST_STYLE_MAP = [
        'hl-keyword' => ['light' => 'color:#cf222e;', 'dark' => 'color:#ff7b72;'],
        'hl-operator' => ['light' => 'color:#0550ae;', 'dark' => 'color:#79c0ff;'],
        'hl-type' => ['light' => 'color:#8250df;', 'dark' => 'color:#d2a8ff;'],
        'hl-value' => ['light' => 'color:#0a3069;', 'dark' => 'color:#a5d6ff;'],
        'hl-variable' => ['light' => 'color:#953800;', 'dark' => 'color:#ffa657;'],
        'hl-property' => ['light' => 'color:#953800;', 'dark' => 'color:#ffa657;'],
        'hl-attribute' => ['light' => 'color:#116329;', 'dark' => 'color:#7ee787;'],
        'hl-generic' => ['light' => 'color:#24292f;', 'dark' => 'color:#e6edf3;'],
        'hl-number' => ['light' => 'color:#0550ae;', 'dark' => 'color:#79c0ff;'],
        'hl-literal' => ['light' => 'color:#0550ae;', 'dark' => 'color:#79c0ff;'],
        'hl-comment' => ['light' => 'color:#6e7781;', 'dark' => 'color:#8b949e;'],
        'hl-injection' => ['light' => 'color:#8250df;', 'dark' => 'color:#d2a8ff;'],
        // Tempest's `diff` language emits these for +/- lines when a .diff/.patch
        // file is itself under review. Without entries here they render unstyled.
        'hl-addition' => ['light' => 'color:#1a7f37;', 'dark' => 'color:#3fb950;'],
        'hl-deletion' => ['light' => 'color:#cf222e;', 'dark' => 'color:#f85149;'],
    ];

    private Phiki $phiki;

    private TempestHighlighter $tempest;

    private ParsedTheme $lightTheme;

    private ParsedTheme $darkTheme;

    /** @var array<string, string> scope key => CSS class name */
    private array $scopeCache = [];

    /** @var array<string, array{light: string, dark: string}> class name => CSS declarations */
    private array $classStyles = [];

    private string $lastHighlighter = 'none';

    public function __construct()
    {
        $this->phiki = new Phiki;
        $this->tempest = new TempestHighlighter;
        $this->lightTheme = $this->phiki->environment->themes->resolve(Theme::GithubLight);
        $this->darkTheme = $this->phiki->environment->themes->resolve(Theme::GithubDark);
    }

    /**
     * @param  Hunk[]  $hunks
     * @return Hunk[]
     */
    public function highlightHunks(array $hunks, string $filePath): array
    {
        $this->scopeCache = [];
        $this->classStyles = [];
        $this->lastHighlighter = 'none';

        if ($hunks === []) {
            return $hunks;
        }

        $tempestLanguage = $this->resolveTempestLanguage($filePath);
        if ($tempestLanguage !== null) {
            $highlighted = $this->highlightWithTempest($hunks, $tempestLanguage);

            if ($highlighted !== null) {
                $this->classStyles = self::TEMPEST_STYLE_MAP;
                $this->lastHighlighter = 'tempest';

                return $highlighted;
            }
        }

        $grammar = GrammarMap::resolve($filePath);
        if ($grammar === null) {
            return $hunks;
        }

        $this->lastHighlighter = 'phiki';

        return array_map(fn (Hunk $hunk) => $this->highlightHunkWithPhiki($hunk, $grammar), $hunks);
    }

    /** @return array<string, array{light: string, dark: string}> */
    public function getStyleMap(): array
    {
        return $this->classStyles;
    }

    public function lastHighlighter(): string
    {
        return $this->lastHighlighter;
    }

    /**
     * @param  Hunk[]  $hunks
     * @return Hunk[]|null
     */
    private function highlightWithTempest(array $hunks, string $language): ?array
    {
        try {
            return array_map(fn (Hunk $hunk) => $this->highlightHunkWithTempest($hunk, $language), $hunks);
        } catch (Throwable $e) {
            Log::warning('syntax.highlighting.failed', [
                'reason' => 'tempest_highlighting_failed',
                'language' => $language,
                'error_class' => $e::class,
            ]);

            return null;
        }
    }

    private function highlightHunkWithTempest(Hunk $hunk, string $language): Hunk
    {
        return $this->highlightHunk(
            $hunk,
            fn (array $codeLines): array => $this->highlightCodeLinesWithTempest($codeLines, $language),
        );
    }

    /**
     * @param  string[]  $codeLines
     * @return string[]
     */
    private function highlightCodeLinesWithTempest(array $codeLines, string $language): array
    {
        if ($codeLines === []) {
            return [];
        }

        if ($language === 'blade') {
            return $this->highlightBladeCodeLinesWithTempest($codeLines);
        }

        $html = $this->tempest->parse(implode("\n", $codeLines), $language);
        $highlightedLines = $this->splitBalancedTempestHtml($html);

        return count($highlightedLines) === count($codeLines) ? $highlightedLines : [];
    }

    /**
     * Split Tempest's single highlighted-HTML string into per-line fragments,
     * re-balancing spans that cross line boundaries.
     *
     * Tempest highlights the whole block at once and wraps multi-line constructs
     * (PHPDoc, block comments, template literals) in `<span>`s that open on one
     * line and close on a later one. Splitting naively on "\n" leaves each cell's
     * HTML unbalanced. Emitted raw via {!! !!}, the browser auto-closes the open
     * span and orphan `</span>`s corrupt structure. Here we track
     * the open-span stack: at each newline we close all open spans to end the
     * line, then re-open them (with their exact tags/classes) to start the next.
     *
     * @return string[]
     */
    private function splitBalancedTempestHtml(string $html): array
    {
        if (preg_match_all('/<span\b[^>]*>|<\/span>|\n/', $html, $matches, PREG_OFFSET_CAPTURE) === 0) {
            return explode("\n", $html);
        }

        $lines = [];
        $line = '';
        $openTags = [];
        $cursor = 0;

        foreach ($matches[0] as [$token, $offset]) {
            $line .= substr($html, $cursor, $offset - $cursor);
            $cursor = $offset + strlen($token);

            if ($token === "\n") {
                $line .= str_repeat('</span>', count($openTags));
                $lines[] = $line;
                $line = implode('', $openTags);
            } elseif ($token === '</span>') {
                $line .= $token;
                array_pop($openTags);
            } else {
                $line .= $token;
                $openTags[] = $token;
            }
        }

        $line .= substr($html, $cursor);
        $lines[] = $line;

        return $lines;
    }

    /**
     * @param  string[]  $codeLines
     * @return string[]
     */
    private function highlightBladeCodeLinesWithTempest(array $codeLines): array
    {
        if (! collect($codeLines)->contains(fn (string $line): bool => str_contains($line, '<?php') || str_contains($line, '?'.'>'))
            && $this->looksLikePhpBlock($codeLines)) {
            return $this->highlightPlainCodeLinesWithTempest($codeLines, 'php');
        }

        /** @var list<array{language: string, lines: string[]}> $segments */
        $segments = [];
        $segmentLines = [];
        $segmentLanguage = 'blade';
        $insidePhpBlock = false;

        foreach ($codeLines as $line) {
            $startsPhpBlock = ! $insidePhpBlock && str_contains($line, '<?php');
            if ($startsPhpBlock && $segmentLanguage !== 'php') {
                if ($segmentLines !== []) {
                    $segments[] = ['language' => $segmentLanguage, 'lines' => $segmentLines];
                }

                $segmentLines = [];
                $segmentLanguage = 'php';
                $insidePhpBlock = true;
            }

            $segmentLines[] = $line;

            if ($insidePhpBlock && str_contains($line, '?'.'>')) {
                $segments[] = ['language' => $segmentLanguage, 'lines' => $segmentLines];
                $segmentLines = [];
                $segmentLanguage = 'blade';
                $insidePhpBlock = false;
            }
        }

        if ($segmentLines !== []) {
            $segments[] = ['language' => $segmentLanguage, 'lines' => $segmentLines];
        }

        $highlightedLines = [];
        foreach ($segments as $segment) {
            $highlightedLines = [
                ...$highlightedLines,
                ...$this->highlightPlainCodeLinesWithTempest($segment['lines'], $segment['language']),
            ];
        }

        return count($highlightedLines) === count($codeLines) ? $highlightedLines : [];
    }

    /**
     * @param  string[]  $codeLines
     */
    private function looksLikePhpBlock(array $codeLines): bool
    {
        $code = trim(implode("\n", array_filter($codeLines, fn (string $line): bool => trim($line) !== '')));

        if ($code === '') {
            return false;
        }

        if (preg_match('/^\s*(<|@|\{\{|\{!!)/m', $code) === 1) {
            return false;
        }

        // Any line that opens with a PHP statement keyword, a declaration, a
        // variable assignment, or an `echo`/`$this->`/`app()` call marks the hunk
        // as PHP rather than Blade markup. The Blade/HTML bail above keeps this
        // from misfiring on template lines.
        return preg_match(
            '/^\s*('
            .'use\s+[A-Za-z_\\\\]|namespace\s+|declare\s*\(|'
            .'(?:abstract\s+|final\s+|readonly\s+)*class\s+|new\s+class\b|'
            .'interface\s+|trait\s+|enum\s+|function\s+|const\s+|'
            .'public\s+|protected\s+|private\s+|static\s+|readonly\s+|'
            .'if\s*\(|elseif\s*\(|else\b|for\s*\(|foreach\s*\(|while\s*\(|do\b|'
            .'switch\s*\(|match\s*\(|try\b|throw\b|return\b|echo\b|print\b|'
            .'\$this->|app\(|'
            .'\$[a-zA-Z_]\w*\s*(?:=[^=]|\[|\?\?=|\.=)'
            .')/m',
            $code,
        ) === 1;
    }

    /**
     * @param  string[]  $codeLines
     * @return string[]
     */
    private function highlightPlainCodeLinesWithTempest(array $codeLines, string $language): array
    {
        if ($codeLines === []) {
            return [];
        }

        $html = $this->tempest->parse(implode("\n", $codeLines), $language);
        $highlightedLines = $this->splitBalancedTempestHtml($html);

        return count($highlightedLines) === count($codeLines) ? $highlightedLines : [];
    }

    private function highlightHunkWithPhiki(Hunk $hunk, Grammar $grammar): Hunk
    {
        return $this->highlightHunk(
            $hunk,
            fn (array $codeLines): array => $this->highlightCodeLinesWithPhiki($codeLines, $grammar),
        );
    }

    /**
     * @param  callable(string[]): string[]  $highlightCodeLines
     */
    private function highlightHunk(Hunk $hunk, callable $highlightCodeLines): Hunk
    {
        $lines = $hunk->lines;
        $oldCode = [];
        $newCode = [];
        $oldIndices = [];
        $newIndices = [];

        foreach ($lines as $i => $line) {
            if ($line->type === LineType::Remove) {
                $oldIndices[] = $i;
                $oldCode[] = $line->content;
            } elseif ($line->type === LineType::Add) {
                $newIndices[] = $i;
                $newCode[] = $line->content;
            } else {
                $oldIndices[] = $i;
                $oldCode[] = $line->content;
                $newIndices[] = $i;
                $newCode[] = $line->content;
            }
        }

        $oldHighlighted = $highlightCodeLines($oldCode);
        $newHighlighted = $highlightCodeLines($newCode);

        $highlighted = [];

        foreach ($oldIndices as $pos => $lineIndex) {
            if (isset($oldHighlighted[$pos]) && $lines[$lineIndex]->type === LineType::Remove) {
                $highlighted[$lineIndex] = $oldHighlighted[$pos];
            }
        }

        foreach ($newIndices as $pos => $lineIndex) {
            if (isset($newHighlighted[$pos])) {
                $highlighted[$lineIndex] = $newHighlighted[$pos];
            }
        }

        $newLines = array_map(
            fn (int $i, DiffLine $line) => array_key_exists($i, $highlighted)
                ? new DiffLine(
                    type: $line->type,
                    content: $line->content,
                    oldLineNum: $line->oldLineNum,
                    newLineNum: $line->newLineNum,
                    highlightedContent: $highlighted[$i],
                    headingLevel: $line->headingLevel,
                    headingId: $line->headingId,
                    headingAncestors: $line->headingAncestors,
                    moved: $line->moved,
                )
                : $line,
            array_keys($lines),
            $lines,
        );

        return new Hunk(
            header: $hunk->header,
            oldStart: $hunk->oldStart,
            oldCount: $hunk->oldCount,
            newStart: $hunk->newStart,
            newCount: $hunk->newCount,
            lines: $newLines,
        );
    }

    /**
     * @param  string[]  $codeLines
     * @return string[]
     */
    private function highlightCodeLinesWithPhiki(array $codeLines, Grammar $grammar): array
    {
        if ($codeLines === []) {
            return [];
        }

        try {
            $code = implode("\n", $codeLines);
            $tokenLines = $this->phiki->codeToTokens($code, $grammar);

            if (count($tokenLines) !== count($codeLines)) {
                return [];
            }

            $result = [];
            foreach ($tokenLines as $lineTokens) {
                $html = '';
                foreach ($lineTokens as $token) {
                    // Phiki emits a trailing "\n" token on every line as the
                    // line separator. The cell uses white-space: pre-wrap,
                    // which would render that newline as a visible second
                    // line and double the row height. Strip it.
                    $tokenText = rtrim($token->text, "\n");
                    if ($tokenText === '') {
                        continue;
                    }
                    $text = e($tokenText);
                    $class = $this->matchScopesToClass($token->scopes);
                    $html .= $class !== '' ? '<span class="'.$class.'">'.$text.'</span>' : $text;
                }
                $result[] = $html;
            }

            return $result;
        } catch (Throwable $e) {
            Log::warning('syntax.highlighting.failed', [
                'reason' => 'phiki_highlighting_failed',
                'error_class' => $e::class,
            ]);

            return [];
        }
    }

    /**
     * @param  list<string>  $scopes
     */
    private function matchScopesToClass(array $scopes): string
    {
        $key = implode("\0", $scopes);

        if (array_key_exists($key, $this->scopeCache)) {
            return $this->scopeCache[$key];
        }

        $lightMatch = $this->lightTheme->match($scopes);
        $darkMatch = $this->darkTheme->match($scopes);

        $lightCss = $lightMatch !== null ? $this->toCssDeclarations($lightMatch->toStyleArray()) : '';
        $darkCss = $darkMatch !== null ? $this->toCssDeclarations($darkMatch->toStyleArray()) : '';

        if ($lightCss === '' && $darkCss === '') {
            return $this->scopeCache[$key] = '';
        }

        $className = '_'.substr(base_convert((string) abs(crc32($key)), 10, 36), 0, 5);
        $this->classStyles[$className] = ['light' => $lightCss, 'dark' => $darkCss];

        return $this->scopeCache[$key] = $className;
    }

    /** @param array<string, string> $styles */
    private function toCssDeclarations(array $styles): string
    {
        unset($styles['background-color']);

        $css = '';
        foreach ($styles as $prop => $value) {
            $css .= $prop.':'.$value.';';
        }

        return $css;
    }

    private function resolveTempestLanguage(string $filePath): ?string
    {
        $filename = strtolower(basename($filePath));

        if (isset(self::TEMPEST_FILENAME_MAP[$filename])) {
            return self::TEMPEST_FILENAME_MAP[$filename];
        }

        if (str_starts_with($filename, '.env.') || str_starts_with($filename, '.dev.vars')) {
            return 'dotenv';
        }

        foreach (self::TEMPEST_COMPOUND_MAP as $compound => $language) {
            if (str_ends_with($filename, '.'.$compound)) {
                return $language;
            }
        }

        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        return self::TEMPEST_EXTENSION_MAP[$extension] ?? null;
    }
}
