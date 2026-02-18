<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\DiffLine;
use App\DTOs\Hunk;
use App\Support\GrammarMap;
use Phiki\Grammar\Grammar;
use Phiki\Phiki;
use Phiki\Theme\Theme;

class SyntaxHighlightService
{
    private Phiki $phiki;

    public function __construct()
    {
        $this->phiki = new Phiki;
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

        $oldHighlighted = $this->highlightAndSplit($oldCode, $grammar);
        $newHighlighted = $this->highlightAndSplit($newCode, $grammar);

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
    private function highlightAndSplit(array $codeLines, Grammar $grammar): array
    {
        if ($codeLines === []) {
            return [];
        }

        try {
            $code = implode("\n", $codeLines);
            $html = $this->phiki->codeToHtml($code, $grammar, [
                'light' => Theme::GithubLight,
                'dark' => Theme::GithubDark,
            ])->toString();

            if (! preg_match_all('/<span class="line">(.*?)<\/span>\s*(?=<span class="line">|<\/code>)/s', $html, $matches)) {
                return [];
            }

            if (count($matches[1]) !== count($codeLines)) {
                return [];
            }

            return $matches[1];
        } catch (\Throwable) {
            return [];
        }
    }
}
