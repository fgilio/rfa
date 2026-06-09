<?php

use App\Services\PatchNormalizerService;

test('normalizes no-prefix git headers and file markers', function () {
    $patch = <<<'PATCH'
diff --git src/Foo.php src/Foo.php
index abc1234..def5678 100644
--- src/Foo.php
+++ src/Foo.php
@@ -1 +1 @@
-old
+new
PATCH;

    $normalized = (new PatchNormalizerService)->normalize($patch);

    expect($normalized)->toContain('diff --git a/src/Foo.php b/src/Foo.php')
        ->and($normalized)->toContain('--- a/src/Foo.php')
        ->and($normalized)->toContain('+++ b/src/Foo.php');
});

test('normalizes matching multi-character prefixes', function () {
    $patch = <<<'PATCH'
diff --git old/src/Foo.php new/src/Foo.php
index abc1234..def5678 100644
--- old/src/Foo.php
+++ new/src/Foo.php
@@ -1 +1 @@
-old
+new
PATCH;

    $normalized = (new PatchNormalizerService)->normalize($patch);

    expect($normalized)->toContain('diff --git a/src/Foo.php b/src/Foo.php')
        ->and($normalized)->toContain('--- a/src/Foo.php')
        ->and($normalized)->toContain('+++ b/src/Foo.php')
        ->and($normalized)->not->toContain('old/src/Foo.php')
        ->and($normalized)->not->toContain('new/src/Foo.php');
});

test('preserves dev null file markers', function () {
    $patch = <<<'PATCH'
diff --git new.txt new.txt
new file mode 100644
--- /dev/null
+++ new.txt
@@ -0,0 +1 @@
+new
PATCH;

    $normalized = (new PatchNormalizerService)->normalize($patch);

    expect($normalized)->toContain('--- /dev/null')
        ->and($normalized)->toContain('+++ b/new.txt');
});

test('does not normalize hunk body marker text', function () {
    $patch = <<<'PATCH'
diff --git file.txt file.txt
index abc1234..def5678 100644
--- file.txt
+++ file.txt
@@ -1,2 +1,2 @@
 --- not a file marker
-old
+new
PATCH;

    $normalized = (new PatchNormalizerService)->normalize($patch);

    expect($normalized)->toContain(" --- not a file marker\n");
});

test('preserves one-letter directories in no-prefix patches', function () {
    $patch = <<<'PATCH'
diff --git x/Foo.php x/Foo.php
index abc1234..def5678 100644
--- x/Foo.php
+++ x/Foo.php
@@ -1 +1 @@
-old
+new
PATCH;

    $normalized = (new PatchNormalizerService)->normalize($patch);

    expect($normalized)->toContain('diff --git a/x/Foo.php b/x/Foo.php')
        ->and($normalized)->toContain('--- a/x/Foo.php')
        ->and($normalized)->toContain('+++ b/x/Foo.php');
});

test('normalizes no-prefix paths that contain spaces', function () {
    $patch = <<<'PATCH'
diff --git src/my file.php src/my file.php
index abc1234..def5678 100644
--- src/my file.php
+++ src/my file.php
@@ -1 +1 @@
-old
+new
PATCH;

    $normalized = (new PatchNormalizerService)->normalize($patch);

    expect($normalized)->toContain('diff --git a/src/my file.php b/src/my file.php')
        ->and($normalized)->toContain('--- a/src/my file.php')
        ->and($normalized)->toContain('+++ b/src/my file.php');
});
