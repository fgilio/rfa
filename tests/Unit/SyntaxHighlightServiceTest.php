<?php

use App\DTOs\DiffLine;
use App\DTOs\Hunk;
use App\Enums\LineType;
use App\Services\SyntaxHighlightService;

beforeEach(function () {
    $this->service = new SyntaxHighlightService;
});

test('highlights PHP hunks', function () {
    $hunks = [
        new Hunk('', 1, 3, 1, 3, [
            new DiffLine(LineType::Context, '<?php', 1, 1),
            new DiffLine(LineType::Remove, 'echo "old";', 2, null),
            new DiffLine(LineType::Add, 'echo "new";', null, 2),
        ]),
    ];

    $result = $this->service->highlightHunks($hunks, 'test.php');

    expect($result[0]->lines[0]->highlightedContent)->not->toBeNull()
        ->and($result[0]->lines[1]->highlightedContent)->not->toBeNull()
        ->and($result[0]->lines[2]->highlightedContent)->not->toBeNull();

    expect($result[0]->lines[0]->highlightedContent)->toContain('<span');
});

test('returns unmodified hunks for unknown grammar', function () {
    $hunks = [
        new Hunk('', 1, 0, 1, 1, [
            new DiffLine(LineType::Add, 'hello', null, 1),
        ]),
    ];

    $result = $this->service->highlightHunks($hunks, 'file.xyz');

    expect($result)->toBe($hunks)
        ->and($result[0]->lines[0]->highlightedContent)->toBeNull();
});

test('handles empty hunks', function () {
    $result = $this->service->highlightHunks([], 'test.php');

    expect($result)->toBe([]);
});

test('uses Tempest for Blade hunks', function () {
    $hunks = [
        new Hunk('', 1, 2, 1, 2, [
            new DiffLine(LineType::Context, '<div>{{ $name }}</div>', 1, 1),
            new DiffLine(LineType::Add, '@if ($name)', null, 2),
        ]),
    ];

    $result = $this->service->highlightHunks($hunks, 'resources/views/pages/example.blade.php');

    expect($this->service->lastHighlighter())->toBe('tempest')
        ->and($result[0]->lines[0]->highlightedContent)->toContain('hl-')
        ->and($result[0]->lines[1]->highlightedContent)->toContain('hl-');
});

test('highlights raw PHP blocks in Livewire SFC Blade files', function () {
    $hunks = [
        new Hunk('', 1, 8, 1, 8, [
            new DiffLine(LineType::Context, '<?php', 1, 1),
            new DiffLine(LineType::Context, 'use App\Actions\LoadFileDiffAction;', 2, 2),
            new DiffLine(LineType::Context, 'new class extends Component {', 3, 3),
            new DiffLine(LineType::Context, '    public string $repoPath = "";', 4, 4),
            new DiffLine(LineType::Context, '};', 5, 5),
            new DiffLine(LineType::Context, '?>', 6, 6),
            new DiffLine(LineType::Context, '<section>{{ $repoPath }}</section>', 7, 7),
            new DiffLine(LineType::Context, '@if ($repoPath)', 8, 8),
        ]),
    ];

    $result = $this->service->highlightHunks($hunks, 'resources/views/livewire/example.blade.php');

    expect($this->service->lastHighlighter())->toBe('tempest')
        ->and($result[0]->lines[1]->highlightedContent)->toContain('<span class="hl-keyword">use</span>')
        ->and($result[0]->lines[3]->highlightedContent)->toContain('<span class="hl-keyword">public</span>')
        ->and($result[0]->lines[6]->highlightedContent)->toContain('<span class="hl-keyword">section</span>')
        ->and($result[0]->lines[7]->highlightedContent)->toContain('<span class="hl-keyword">@if</span>');
});

test('highlights Blade hunks that start inside a Livewire SFC PHP block', function () {
    $hunks = [
        new Hunk('', 79, 7, 79, 7, [
            new DiffLine(LineType::Context, '    public function loadFileDiff(): void', 79, 79),
            new DiffLine(LineType::Context, '    {', 80, 80),
            new DiffLine(LineType::Add, '        $startedAt = microtime(true);', null, 81),
            new DiffLine(LineType::Context, '', 81, 82),
            new DiffLine(LineType::Context, '        if ($this->diffData !== null) {', 82, 83),
            new DiffLine(LineType::Context, '            return;', 83, 84),
            new DiffLine(LineType::Context, '        }', 84, 85),
        ]),
    ];

    $result = $this->service->highlightHunks($hunks, 'resources/views/livewire/example.blade.php');

    expect($this->service->lastHighlighter())->toBe('tempest')
        ->and($result[0]->lines[0]->highlightedContent)->toContain('<span class="hl-keyword">public</span>')
        ->and($result[0]->lines[2]->highlightedContent)->toContain('<span class="hl-variable">$startedAt</span>')
        ->and($result[0]->lines[4]->highlightedContent)->toContain('<span class="hl-keyword">if</span>');
});

test('context lines get new-side highlighting', function () {
    $hunks = [
        new Hunk('', 1, 1, 1, 1, [
            new DiffLine(LineType::Context, '$x = 1;', 1, 1),
        ]),
    ];

    $result = $this->service->highlightHunks($hunks, 'test.php');

    expect($result[0]->lines[0]->highlightedContent)->not->toBeNull()
        ->and($result[0]->lines[0]->highlightedContent)->toContain('<span');
});

test('removed lines get old-side highlighting', function () {
    $hunks = [
        new Hunk('', 1, 1, 1, 0, [
            new DiffLine(LineType::Remove, '$old = true;', 1, null),
        ]),
    ];

    $result = $this->service->highlightHunks($hunks, 'test.php');

    expect($result[0]->lines[0]->highlightedContent)->not->toBeNull()
        ->and($result[0]->lines[0]->highlightedContent)->toContain('<span');
});

test('preserves original content field', function () {
    $hunks = [
        new Hunk('', 1, 0, 1, 1, [
            new DiffLine(LineType::Add, 'echo "hello";', null, 1),
        ]),
    ];

    $result = $this->service->highlightHunks($hunks, 'test.php');

    expect($result[0]->lines[0]->content)->toBe('echo "hello";');
});

test('highlights mixed add/remove/context hunk with asymmetric sides', function () {
    $hunks = [
        new Hunk('@@ -1,4 +1,3 @@', 1, 4, 1, 3, [
            new DiffLine(LineType::Context, '<?php', 1, 1),
            new DiffLine(LineType::Remove, '$a = 1;', 2, null),
            new DiffLine(LineType::Remove, '$b = 2;', 3, null),
            new DiffLine(LineType::Add, '$c = 3;', null, 2),
            new DiffLine(LineType::Context, 'return true;', 4, 3),
        ]),
    ];

    $result = $this->service->highlightHunks($hunks, 'test.php');

    foreach ($result[0]->lines as $line) {
        expect($line->highlightedContent)->not->toBeNull();
    }

    expect($result[0]->lines[1]->content)->toBe('$a = 1;')
        ->and($result[0]->lines[3]->content)->toBe('$c = 3;');
});

test('handles hunk with only context lines', function () {
    $hunks = [
        new Hunk('', 1, 3, 1, 3, [
            new DiffLine(LineType::Context, '<?php', 1, 1),
            new DiffLine(LineType::Context, '', 2, 2),
            new DiffLine(LineType::Context, 'return true;', 3, 3),
        ]),
    ];

    $result = $this->service->highlightHunks($hunks, 'test.php');

    foreach ($result[0]->lines as $line) {
        expect($line->highlightedContent)->not->toBeNull();
    }
});

// -- Multi-hunk --

test('multi-hunk diff highlights all hunks independently', function () {
    // Hunk 1: class declaration area with comment additions
    // Hunk 2: inside method body, far from hunk 1
    // Ensures each hunk is tokenized in isolation - concatenating lines
    // from distant hunks would break the tokenizer's grammar state
    $hunks = [
        new Hunk('@@ -5,3 +5,6 @@', 5, 3, 5, 6, [
            new DiffLine(LineType::Context, 'use Illuminate\Support\Str;', 5, 5),
            new DiffLine(LineType::Add, '// A comment block', null, 6),
            new DiffLine(LineType::Add, '// that spans lines', null, 7),
            new DiffLine(LineType::Context, '', 6, 8),
            new DiffLine(LineType::Context, 'class Example {', 7, 9),
        ]),
        new Hunk('@@ -20,3 +23,3 @@', 20, 3, 23, 3, [
            new DiffLine(LineType::Context, '    public function run(): void {', 20, 23),
            new DiffLine(LineType::Remove, '        $old = true;', 21, null),
            new DiffLine(LineType::Add, '        foreach ($items as $i) {', null, 24),
        ]),
    ];

    $result = $this->service->highlightHunks($hunks, 'test.php');

    // Every non-empty line in both hunks must be highlighted
    foreach ($result as $hunk) {
        foreach ($hunk->lines as $line) {
            expect($line->highlightedContent)->not->toBeNull();
            if ($line->content !== '') {
                expect($line->highlightedContent)->toContain('<span');
            }
        }
    }

    // Hunk 2's foreach keyword must have a distinct color (not default text)
    // This would fail if the tokenizer state from hunk 1 bled into hunk 2
    $h2add = $result[1]->lines[2]->highlightedContent;
    expect($h2add)->toContain('foreach');

    preg_match_all('/<span\s/', $h2add, $spans);
    expect(count($spans[0]))->toBeGreaterThan(2, 'foreach line should have multiple styled tokens');
});

test('style map has entries with different light and dark colors', function () {
    $hunks = [
        new Hunk('', 1, 0, 1, 1, [
            new DiffLine(LineType::Add, 'echo "hello";', null, 1),
        ]),
    ];

    $this->service->highlightHunks($hunks, 'test.php');
    $styleMap = $this->service->getStyleMap();

    expect($styleMap)->not->toBeEmpty();

    $hasDifference = collect($styleMap)->contains(fn ($styles) => $styles['light'] !== $styles['dark']);
    expect($hasDifference)->toBeTrue();
});

test('getStyleMap returns expected structure', function () {
    $hunks = [
        new Hunk('', 1, 0, 1, 1, [
            new DiffLine(LineType::Add, 'echo "hello";', null, 1),
        ]),
    ];

    $this->service->highlightHunks($hunks, 'test.php');
    $styleMap = $this->service->getStyleMap();

    foreach ($styleMap as $className => $styles) {
        expect($className)->toStartWith('hl-')
            ->and($styles)->toHaveKeys(['light', 'dark'])
            ->and($styles['light'])->toBeString()
            ->and($styles['dark'])->toBeString();
    }
});

test('output uses class not style attribute', function () {
    $hunks = [
        new Hunk('', 1, 3, 1, 3, [
            new DiffLine(LineType::Context, '<?php', 1, 1),
            new DiffLine(LineType::Remove, 'echo "old";', 2, null),
            new DiffLine(LineType::Add, 'echo "new";', null, 2),
        ]),
    ];

    $result = $this->service->highlightHunks($hunks, 'test.php');

    foreach ($result[0]->lines as $line) {
        if ($line->highlightedContent !== null) {
            expect($line->highlightedContent)->not->toContain('style=')
                ->and($line->highlightedContent)->toContain('class=');
        }
    }
});

test('no background-color in style map values', function () {
    $hunks = [
        new Hunk('', 1, 0, 1, 1, [
            new DiffLine(LineType::Add, 'echo "hello";', null, 1),
        ]),
    ];

    $this->service->highlightHunks($hunks, 'test.php');
    $styleMap = $this->service->getStyleMap();

    foreach ($styleMap as $styles) {
        expect($styles['light'])->not->toContain('background-color')
            ->and($styles['dark'])->not->toContain('background-color');
    }
});

test('all class names in highlighted HTML have matching style map entries', function () {
    $hunks = [
        new Hunk('', 1, 3, 1, 3, [
            new DiffLine(LineType::Context, '<?php', 1, 1),
            new DiffLine(LineType::Remove, 'echo "old";', 2, null),
            new DiffLine(LineType::Add, 'echo "new";', null, 2),
        ]),
    ];

    $result = $this->service->highlightHunks($hunks, 'test.php');
    $styleMap = $this->service->getStyleMap();

    $classNames = [];
    foreach ($result[0]->lines as $line) {
        if ($line->highlightedContent !== null) {
            preg_match_all('/class="([^"]+)"/', $line->highlightedContent, $matches);
            foreach ($matches[1] as $cls) {
                $classNames[$cls] = true;
            }
        }
    }

    expect($classNames)->not->toBeEmpty();

    foreach (array_keys($classNames) as $cls) {
        expect($styleMap)->toHaveKey($cls);
    }
});

test('returns Hunk DTOs not arrays', function () {
    $hunks = [
        new Hunk('', 1, 1, 1, 1, [
            new DiffLine(LineType::Add, '$x = 1;', null, 1),
        ]),
    ];

    $result = $this->service->highlightHunks($hunks, 'test.php');

    expect($result[0])->toBeInstanceOf(Hunk::class)
        ->and($result[0]->lines[0])->toBeInstanceOf(DiffLine::class);
});
