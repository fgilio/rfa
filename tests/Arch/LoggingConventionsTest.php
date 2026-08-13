<?php

/**
 * Wide-events logging rules that are intentionally simple and robust.
 *
 * These tests use the current production `Log::*` and `Context::*` calls as
 * their coverage surface (see .claude/skills/wide-events/SKILL.md). They cover
 * the mechanically checkable rules: debug-free production code, static dotted
 * event names, payload-free info events, stable `reason` codes, `rfa.`-namespaced
 * context keys, the approved `rfa.outcome` vocabulary, banned absolute-path keys,
 * repository roots in warning payloads, and raw exception text in payloads.
 *
 * Semantic rules — logging ownership, context richness, and deeper privacy
 * judgment — still belong in review because they require call-chain and domain
 * judgment. The recursively-resolved channel posture (C8) needs the Laravel
 * config and lives in tests/Feature/LogChannelPostureTest.php, since arch tests
 * run without app context.
 */
/**
 * @return list<string>
 */
function loggingConventionFiles(): array
{
    static $files;

    if ($files !== null) {
        return $files;
    }

    $root = dirname(__DIR__, 2);
    $directories = [
        $root.'/app',
        $root.'/resources/views',
    ];

    $found = [];

    foreach ($directories as $directory) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $found[] = $file->getPathname();
            }
        }
    }

    sort($found);

    return $files = $found;
}

/**
 * Production file contents, keyed by path and read once per process. Every rule
 * scans the same files, so the read happens a single time for the whole suite.
 *
 * @return array<string, string>
 */
function loggingConventionFileContents(): array
{
    static $contents;

    if ($contents !== null) {
        return $contents;
    }

    $contents = [];

    foreach (loggingConventionFiles() as $file) {
        $raw = file_get_contents($file);

        if ($raw !== false) {
            $contents[$file] = $raw;
        }
    }

    return $contents;
}

/**
 * Every paren-balanced call whose opening `Facade::method(` matches $pattern,
 * where the pattern's first capture group is the method name (stored as
 * `match`). Memoized per pattern; reused by the Log:: and Context:: scanners.
 *
 * @return list<array{match: string, source: string, line: int, path: string}>
 */
function conventionCalls(string $pattern): array
{
    static $cache = [];

    if (array_key_exists($pattern, $cache)) {
        return $cache[$pattern];
    }

    $root = dirname(__DIR__, 2).'/';
    $calls = [];

    foreach (loggingConventionFileContents() as $file => $contents) {
        preg_match_all($pattern, $contents, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] as $index => $match) {
            $offset = $match[1];
            $source = extractLoggingConventionCall($contents, $offset);

            if ($source === null) {
                continue;
            }

            $calls[] = [
                'match' => $matches[1][$index][0],
                'source' => $source,
                'line' => substr_count(substr($contents, 0, $offset), "\n") + 1,
                'path' => str_replace($root, '', $file),
            ];
        }
    }

    return $cache[$pattern] = $calls;
}

/**
 * @return list<array{level: string, source: string, line: int, path: string}>
 */
function loggingConventionCalls(): array
{
    return array_map(
        fn (array $call): array => [
            'level' => $call['match'],
            'source' => $call['source'],
            'line' => $call['line'],
            'path' => $call['path'],
        ],
        conventionCalls('/Log::(debug|info|warning|error|critical)\s*\(/'),
    );
}

function extractLoggingConventionCall(string $contents, int $offset): ?string
{
    $open = strpos($contents, '(', $offset);

    if ($open === false) {
        return null;
    }

    $depth = 0;
    $quote = null;
    $escaped = false;
    $length = strlen($contents);

    for ($index = $open; $index < $length; $index++) {
        $char = $contents[$index];

        if ($quote !== null) {
            if ($escaped) {
                $escaped = false;

                continue;
            }

            if ($char === '\\') {
                $escaped = true;

                continue;
            }

            if ($char === $quote) {
                $quote = null;
            }

            continue;
        }

        if ($char === '"' || $char === "'") {
            $quote = $char;

            continue;
        }

        if ($char === '(') {
            $depth++;

            continue;
        }

        if ($char === ')') {
            $depth--;

            if ($depth === 0) {
                return substr($contents, $offset, $index - $offset + 1);
            }
        }
    }

    return null;
}

/**
 * @return list<string>
 */
function loggingConventionArguments(string $call): array
{
    $open = strpos($call, '(');

    if ($open === false) {
        return [];
    }

    $inside = substr($call, $open + 1, -1);
    $arguments = [];
    $start = 0;
    $parenDepth = 0;
    $arrayDepth = 0;
    $braceDepth = 0;
    $quote = null;
    $escaped = false;
    $length = strlen($inside);

    for ($index = 0; $index < $length; $index++) {
        $char = $inside[$index];

        if ($quote !== null) {
            if ($escaped) {
                $escaped = false;

                continue;
            }

            if ($char === '\\') {
                $escaped = true;

                continue;
            }

            if ($char === $quote) {
                $quote = null;
            }

            continue;
        }

        if ($char === '"' || $char === "'") {
            $quote = $char;

            continue;
        }

        if ($char === '(') {
            $parenDepth++;

            continue;
        }

        if ($char === ')') {
            $parenDepth--;

            continue;
        }

        if ($char === '[') {
            $arrayDepth++;

            continue;
        }

        if ($char === ']') {
            $arrayDepth--;

            continue;
        }

        if ($char === '{') {
            $braceDepth++;

            continue;
        }

        if ($char === '}') {
            $braceDepth--;

            continue;
        }

        if ($char === ',' && $parenDepth === 0 && $arrayDepth === 0 && $braceDepth === 0) {
            $arguments[] = trim(substr($inside, $start, $index - $start));
            $start = $index + 1;
        }
    }

    $tail = trim(substr($inside, $start));

    if ($tail !== '') {
        $arguments[] = $tail;
    }

    return $arguments;
}

test('production code does not use debug logs', function () {
    $violations = collect(loggingConventionCalls())
        ->filter(fn (array $call): bool => $call['level'] === 'debug')
        ->map(fn (array $call): string => "{$call['path']}:{$call['line']}")
        ->values()
        ->all();

    expect($violations)->toBeEmpty();
});

test('production log event names are static lowercase dotted names', function () {
    $violations = [];

    foreach (loggingConventionCalls() as $call) {
        if ($call['level'] === 'debug') {
            continue;
        }

        $arguments = loggingConventionArguments($call['source']);
        $event = $arguments[0] ?? '';

        if (! preg_match('/^[\'"][a-z0-9_]+(?:\.[a-z0-9_]+){1,3}[\'"]$/', $event)) {
            $violations[] = "{$call['path']}:{$call['line']} {$call['source']}";
        }
    }

    expect($violations)->toBeEmpty();
});

test('info logs use Context instead of inline payloads', function () {
    $violations = [];

    foreach (loggingConventionCalls() as $call) {
        if ($call['level'] !== 'info') {
            continue;
        }

        if (count(loggingConventionArguments($call['source'])) > 1) {
            $violations[] = "{$call['path']}:{$call['line']} {$call['source']}";
        }
    }

    expect($violations)->toBeEmpty();
});

test('warning and error logs include a stable reason payload', function () {
    $violations = [];

    foreach (loggingConventionCalls() as $call) {
        if (! in_array($call['level'], ['warning', 'error', 'critical'], true)) {
            continue;
        }

        $arguments = loggingConventionArguments($call['source']);
        $payload = $arguments[1] ?? '';

        if (! preg_match('/[\'"]reason[\'"]\s*=>/', $payload)) {
            $violations[] = "{$call['path']}:{$call['line']} {$call['source']}";
        }
    }

    expect($violations)->toBeEmpty();
});

test('warning and error logs do not expose repository root paths', function () {
    $violations = [];

    foreach (loggingConventionCalls() as $call) {
        if (! in_array($call['level'], ['warning', 'error', 'critical'], true)) {
            continue;
        }

        $arguments = loggingConventionArguments($call['source']);
        $payload = $arguments[1] ?? '';

        if (preg_match('/=>\s*\$repoPath\b/', $payload)) {
            $violations[] = "{$call['path']}:{$call['line']} {$call['source']}";
        }
    }

    expect($violations)->toBeEmpty();
});

// -- Context facade conventions (C4, C6, C7) and payload privacy (C9) --

/**
 * Every production `Context::add()` / `addIf()` / `addHidden()` / `push()` /
 * `increment()` / `decrement()` call, with the same paren-balanced extraction
 * used for log calls.
 *
 * @return list<array{method: string, source: string, line: int, path: string}>
 */
function contextConventionCalls(): array
{
    return array_map(
        fn (array $call): array => [
            'method' => $call['match'],
            'source' => $call['source'],
            'line' => $call['line'],
            'path' => $call['path'],
        ],
        conventionCalls('/Context::(add|addIf|addHidden|push|increment|decrement)\s*\(/'),
    );
}

/**
 * The literal string an argument resolves to, or null when it is dynamic
 * (a variable, concatenation, or expression) and therefore not statically
 * checkable. Interpolated and escaped literals are treated as dynamic.
 */
function staticStringLiteral(string $argument): ?string
{
    $argument = trim($argument);

    if (preg_match("/^'([^'\\\\]*)'$/", $argument, $matches)) {
        return $matches[1];
    }

    if (preg_match('/^"([^"\\\\$]*)"$/', $argument, $matches)) {
        return $matches[1];
    }

    return null;
}

test('static context keys are namespaced under rfa.', function () {
    $violations = [];

    foreach (contextConventionCalls() as $call) {
        $arguments = loggingConventionArguments($call['source']);
        $key = staticStringLiteral($arguments[0] ?? '');

        if ($key === null) {
            continue; // dynamic key — not statically checkable, left to review
        }

        if (! str_starts_with($key, 'rfa.')) {
            $violations[] = "{$call['path']}:{$call['line']} {$call['source']}";
        }
    }

    expect($violations)->toBeEmpty();
});

test('static rfa.outcome values use the approved vocabulary', function () {
    $vocabulary = ['completed', 'error', 'skipped', 'cancelled', 'rejected', 'partial'];
    $violations = [];

    foreach (contextConventionCalls() as $call) {
        if (! in_array($call['method'], ['add', 'addIf'], true)) {
            continue;
        }

        $arguments = loggingConventionArguments($call['source']);

        if (staticStringLiteral($arguments[0] ?? '') !== 'rfa.outcome') {
            continue;
        }

        $value = staticStringLiteral($arguments[1] ?? '');

        if ($value === null) {
            continue; // dynamic value (e.g. $outcome) — verified by the owning code/review
        }

        if (! in_array($value, $vocabulary, true)) {
            $violations[] = "{$call['path']}:{$call['line']} {$call['source']}";
        }
    }

    expect($violations)->toBeEmpty();
});

test('static context keys never expose absolute filesystem paths', function () {
    $banned = ['rfa.absolute_path', 'rfa.full_path', 'rfa.root_path', 'rfa.repo_path', 'rfa.repoPath'];
    $violations = [];

    foreach (contextConventionCalls() as $call) {
        $arguments = loggingConventionArguments($call['source']);
        $key = staticStringLiteral($arguments[0] ?? '');

        if ($key === null) {
            continue;
        }

        if (in_array($key, $banned, true) || str_ends_with($key, '.absolute_path')) {
            $violations[] = "{$call['path']}:{$call['line']} {$call['source']}";
        }
    }

    expect($violations)->toBeEmpty();
});

test('log and context payloads never carry raw exception text', function () {
    // A raw exception message, stack trace, or process stderr reaching a payload
    // value, in any argument position (including the nullsafe `?->` form).
    // `LogSanitizer::summary(...)` is the one sanctioned wrapper, so its argument
    // list is stripped before the check — `summary($e->stderr)` is allowed, a
    // bare `$e->stderr` anywhere else is not.
    $sanctionedWrapper = '/LogSanitizer::summary\([^)]*\)/';
    $rawExceptionText = '/\$[A-Za-z_]\w*\??->(getMessage\(\)|getTraceAsString\(\)|stderr\b)/';

    $carriesRawText = function (string $source) use ($sanctionedWrapper, $rawExceptionText): bool {
        $stripped = (string) preg_replace($sanctionedWrapper, '', $source);

        return (bool) preg_match($rawExceptionText, $stripped);
    };

    $violations = [];

    foreach (loggingConventionCalls() as $call) {
        if (! in_array($call['level'], ['warning', 'error', 'critical'], true)) {
            continue;
        }

        if ($carriesRawText($call['source'])) {
            $violations[] = "{$call['path']}:{$call['line']} {$call['source']}";
        }
    }

    foreach (contextConventionCalls() as $call) {
        if ($carriesRawText($call['source'])) {
            $violations[] = "{$call['path']}:{$call['line']} {$call['source']}";
        }
    }

    expect($violations)->toBeEmpty();
});
