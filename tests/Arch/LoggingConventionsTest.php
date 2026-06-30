<?php

/**
 * Wide-events logging rules that are intentionally simple and robust.
 *
 * These tests use the current production `Log::*` and `Context::*` calls as
 * their coverage surface (see .claude/skills/wide-events/SKILL.md). They cover
 * the mechanically checkable rules: debug-free production code, static dotted
 * event names, payload-free info events, stable `reason` codes, `rfa.`-namespaced
 * context keys, the approved `rfa.outcome` vocabulary, banned absolute-path keys,
 * and the privacy ban on raw exception text in payloads.
 *
 * Semantic rules — logging ownership, context richness, and deeper privacy
 * judgment — still belong in review because they require call-chain and domain
 * judgment. The recursively-resolved channel posture (C8) needs the Laravel
 * config and lives in tests/Feature/LogChannelPostureTest.php, since arch tests
 * run without app context.
 */
function loggingConventionFiles(): array
{
    $root = dirname(__DIR__, 2);
    $directories = [
        $root.'/app',
        $root.'/resources/views',
    ];

    $files = [];

    foreach ($directories as $directory) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
    }

    sort($files);

    return $files;
}

/**
 * @return list<array{level: string, source: string, line: int, path: string}>
 */
function loggingConventionCalls(): array
{
    $calls = [];

    foreach (loggingConventionFiles() as $file) {
        $contents = file_get_contents($file);

        if ($contents === false) {
            continue;
        }

        preg_match_all('/Log::(debug|info|warning|error|critical)\s*\(/', $contents, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] as $index => $match) {
            $offset = $match[1];
            $source = extractLoggingConventionCall($contents, $offset);

            if ($source === null) {
                continue;
            }

            $calls[] = [
                'level' => $matches[1][$index][0],
                'source' => $source,
                'line' => substr_count(substr($contents, 0, $offset), "\n") + 1,
                'path' => str_replace(dirname(__DIR__, 2).'/', '', $file),
            ];
        }
    }

    return $calls;
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
    $calls = [];

    foreach (loggingConventionFiles() as $file) {
        $contents = file_get_contents($file);

        if ($contents === false) {
            continue;
        }

        preg_match_all('/Context::(add|addIf|addHidden|push|increment|decrement)\s*\(/', $contents, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] as $index => $match) {
            $offset = $match[1];
            $source = extractLoggingConventionCall($contents, $offset);

            if ($source === null) {
                continue;
            }

            $calls[] = [
                'method' => $matches[1][$index][0],
                'source' => $source,
                'line' => substr_count(substr($contents, 0, $offset), "\n") + 1,
                'path' => str_replace(dirname(__DIR__, 2).'/', '', $file),
            ];
        }
    }

    return $calls;
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
    // A raw exception message, stack trace, or process stderr passed straight
    // into a payload value (after `=>` or as a positional argument). Wrapped
    // forms such as `LogSanitizer::summary($e->stderr)` are allowed because the
    // delimiter that precedes the property access is `(`, not `=>` / `,`.
    $rawExceptionText = '/(=>|,)\s*\$[A-Za-z_]\w*->(getMessage\(\)|getTraceAsString\(\)|stderr\b)/';
    $violations = [];

    foreach (loggingConventionCalls() as $call) {
        if (! in_array($call['level'], ['warning', 'error', 'critical'], true)) {
            continue;
        }

        if (preg_match($rawExceptionText, $call['source'])) {
            $violations[] = "{$call['path']}:{$call['line']} {$call['source']}";
        }
    }

    foreach (contextConventionCalls() as $call) {
        if (preg_match($rawExceptionText, $call['source'])) {
            $violations[] = "{$call['path']}:{$call['line']} {$call['source']}";
        }
    }

    expect($violations)->toBeEmpty();
});
