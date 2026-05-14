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

use function e;

// Performance notes
//
// The pipeline is: git diff -> parse -> tokenize -> theme match -> HTML.
// Profiling shows tokenization + theme matching dominate (~95% of total time).
// Two optimizations target this:
//
// 1. Direct token API (bypasses Phast DOM)
//    Phiki's codeToHtml() builds a full DOM tree (Element, Text, Properties,
//    ClassList per token), serializes it to HTML, then we regex-extract lines.
//    Instead, we call codeToTokens() and build flat HTML strings directly,
//    skipping all intermediate DOM allocation.
//
// 2. Scope-cached theme matching (bypasses Phiki's Highlighter)
//    ParsedTheme::match() iterates 100+ theme rules per token, with usort()
//    for specificity. Many tokens share identical scope arrays ($variables,
//    keywords, etc). We cache the resolved CSS style string by scope key,
//    turning repeated O(rules) lookups into O(1) hash hits. For a ~300 line
//    PHP file this cuts theme matching from ~400ms to ~50ms.
//
// NOTE: Each hunk is tokenized independently (not batched across hunks).
// Batching would break the tokenizer's grammar state at hunk boundaries
// because inter-hunk lines are absent from the diff.
class SyntaxHighlightService
{
    private Phiki $phiki;

    private ParsedTheme $lightTheme;

    private ParsedTheme $darkTheme;

    /** @var array<string, string> scope key => CSS class name */
    private array $scopeCache = [];

    /** @var array<string, array{light: string, dark: string}> class name => CSS declarations */
    private array $classStyles = [];

    public function __construct()
    {
        $this->phiki = new Phiki;
        $this->lightTheme = $this->phiki->environment->themes->resolve(Theme::GithubLight);
        $this->darkTheme = $this->phiki->environment->themes->resolve(Theme::GithubDark);
    }

    /**
     * @param  Hunk[]  $hunks
     * @return Hunk[]
     */
    public function highlightHunks(array $hunks, string $filePath): array
    {
        $grammar = GrammarMap::resolve($filePath);

        if ($grammar === null) {
            return $hunks;
        }

        $this->scopeCache = [];
        $this->classStyles = [];

        return array_map(fn (Hunk $hunk) => $this->highlightHunk($hunk, $grammar), $hunks);
    }

    /** @return array<string, array{light: string, dark: string}> */
    public function getStyleMap(): array
    {
        return $this->classStyles;
    }

    private function highlightHunk(Hunk $hunk, Grammar $grammar): Hunk
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

        $oldHighlighted = $this->tokenizeAndHighlight($oldCode, $grammar);
        $newHighlighted = $this->tokenizeAndHighlight($newCode, $grammar);

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
            fn (int $i, DiffLine $line) => isset($highlighted[$i])
                ? new DiffLine($line->type, $line->content, $line->oldLineNum, $line->newLineNum, $highlighted[$i])
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
    private function tokenizeAndHighlight(array $codeLines, Grammar $grammar): array
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
                    // line and double the row height — strip it.
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
        } catch (\Throwable $e) {
            Log::warning('syntax.highlighting.failed', [
                'reason' => 'syntax_highlighting_failed',
                'error' => $e->getMessage(),
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
}
