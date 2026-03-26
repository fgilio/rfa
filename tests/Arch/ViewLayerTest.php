<?php

test('blade components do not resolve services directly via app()', function () {
    $viewDir = dirname(__DIR__, 2).'/resources/views';
    $bladeFiles = glob($viewDir.'/{,*/,*/*/}*.blade.php', GLOB_BRACE);

    expect($bladeFiles)->not->toBeEmpty();

    foreach ($bladeFiles as $file) {
        $content = file_get_contents($file);
        $basename = basename($file);

        expect($content)->not->toMatch(
            '/app\s*\(\s*\\\\?App\\\\Services\\\\/',
            "Blade file {$basename} resolves a Service directly via app(). Use an Action instead."
        );
    }
});
