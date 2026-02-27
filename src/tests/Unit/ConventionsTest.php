<?php

$srcRoot = dirname(__DIR__, 2);

test('test files use expect() instead of PHPUnit assertions', function () use ($srcRoot) {
    $pattern = '/\$this->assert(True|False|Equals|Same|Null|NotNull|Count|Contains|InstanceOf|Array|Empty|NotEmpty|Greater|Less|String|Int|Float|Bool|Numeric|Is|That|Json|Status|See|Dont)\b/';

    $violations = [];

    foreach (glob($srcRoot.'/tests/**/*.php') as $file) {
        // Skip this test file itself
        if (basename($file) === 'ConventionsTest.php') {
            continue;
        }

        $content = file_get_contents($file);
        if (preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
            $relative = str_replace($srcRoot.'/', '', $file);
            foreach ($matches[0] as [$match, $offset]) {
                $line = substr_count(substr($content, 0, $offset), "\n") + 1;
                $violations[] = "{$relative}:{$line} — {$match}";
            }
        }
    }

    expect($violations)->toBeEmpty();
});

test('blade files do not hardcode hex colors', function () use ($srcRoot) {
    $pattern = '/#[0-9a-fA-F]{3,8}\b/';

    // Common false positives: anchors, Livewire directives, blade comments, Alpine expressions
    $falsePositivePatterns = [
        '/\{\{--.*#[0-9a-fA-F]{3,8}.*--\}\}/', // Blade comments
        '/#\[/',                                   // PHP attributes
    ];

    $violations = [];

    foreach (glob($srcRoot.'/resources/views/**/*.blade.php') as $file) {
        $lines = file($file);
        $relative = str_replace($srcRoot.'/', '', $file);

        foreach ($lines as $i => $line) {
            if (preg_match($pattern, $line)) {
                // Skip false positives
                $isFalsePositive = false;
                foreach ($falsePositivePatterns as $fp) {
                    if (preg_match($fp, $line)) {
                        $isFalsePositive = true;
                        break;
                    }
                }
                if (! $isFalsePositive) {
                    $lineNum = $i + 1;
                    $violations[] = "{$relative}:{$lineNum} — ".trim($line);
                }
            }
        }
    }

    expect($violations)->toBeEmpty();
});

test('Flux::toast() calls always pass the required text argument', function () use ($srcRoot) {
    // Valid: Flux::toast('msg'), Flux::toast(text: 'msg'), Flux::toast('msg', variant: 'success')
    // Invalid: Flux::toast(variant: 'success', heading: 'x') - missing $text
    $pattern = '/Flux::toast\((.+?)\)/s';

    $violations = [];

    foreach (glob($srcRoot.'/resources/views/**/*.blade.php') as $file) {
        $content = file_get_contents($file);
        $relative = str_replace($srcRoot.'/', '', $file);

        if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($matches as $match) {
                $args = $match[1][0];
                $offset = $match[0][1];
                $line = substr_count(substr($content, 0, $offset), "\n") + 1;

                // $text is satisfied if: first arg is positional (no `name:` prefix),
                // or `text:` is passed as a named argument
                $hasPositionalFirst = ! preg_match('/^\s*\w+\s*:/', $args);
                $hasNamedText = preg_match('/\btext\s*:/', $args);

                if (! $hasPositionalFirst && ! $hasNamedText) {
                    $violations[] = "{$relative}:{$line} — Flux::toast() missing required \$text argument";
                }
            }
        }
    }

    expect($violations)->toBeEmpty();
});
