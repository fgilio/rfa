<?php

/**
 * Wide-events logging rules that are intentionally simple and robust.
 *
 * These tests use the current production log calls as their coverage surface.
 * Semantic rules like ownership, context richness, and privacy still belong in
 * review because they require call-chain and domain judgment.
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
