<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\DiffLine;
use App\DTOs\Hunk;
use App\Support\GrammarMap;
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

    private ParsedTheme $activeTheme;

    /** @var array<string, string> scope key => CSS style string */
    private array $scopeCache = [];

    public function __construct()
    {
        $this->phiki = new Phiki;
        $this->lightTheme = $this->phiki->environment->themes->resolve(Theme::GithubLight);
        $this->darkTheme = $this->phiki->environment->themes->resolve(Theme::GithubDark);
        $this->activeTheme = $this->lightTheme;
    }

    /**
     * @param  Hunk[]  $hunks
     * @return Hunk[]
     */
    public function highlightHunks(array $hunks, string $filePath, string $theme = 'light'): array
    {
        $grammar = GrammarMap::resolve($filePath);

        if ($grammar === null) {
            return $hunks;
        }

        $this->activeTheme = $theme === 'dark' ? $this->darkTheme : $this->lightTheme;
        $this->scopeCache = [];

        return array_map(fn (Hunk $hunk) => $this->highlightHunk($hunk, $grammar), $hunks);
    }

    private function highlightHunk(Hunk $hunk, Grammar $grammar): Hunk
    {
        $lines = $hunk->lines;
        $oldCode = [];
        $newCode = [];
        $oldIndices = [];
        $newIndices = [];

        foreach ($lines as $i => $line) {
            if ($line->type === 'remove') {
                $oldIndices[] = $i;
                $oldCode[] = $line->content;
            } elseif ($line->type === 'add') {
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
            if (isset($oldHighlighted[$pos]) && $lines[$lineIndex]->type === 'remove') {
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
                    $text = e($token->text);
                    $style = $this->matchScopesToStyle($token->scopes);
                    $html .= $style !== '' ? '<span style="'.$style.'">'.$text.'</span>' : $text;
                }
                $result[] = $html;
            }

            return $result;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param  list<string>  $scopes
     */
    private function matchScopesToStyle(array $scopes): string
    {
        $key = implode("\0", $scopes);

        if (array_key_exists($key, $this->scopeCache)) {
            return $this->scopeCache[$key];
        }

        $match = $this->activeTheme->match($scopes);
        $style = $match !== null ? $match->toStyleString() : '';

        return $this->scopeCache[$key] = $style;
    }
}
