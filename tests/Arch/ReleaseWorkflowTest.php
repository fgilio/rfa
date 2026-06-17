<?php

/**
 * Duplicate-draft race guard. electron-builder's GitHub publisher creates a
 * draft release per artifact when none exists yet, so concurrent artifact
 * uploads (zip, dmg, blockmaps, latest-mac.yml) can each create a draft and
 * split the assets across two incomplete releases. A published release built
 * from a split draft 404s the macOS updater (latest-mac.yml points at a .zip
 * that lives on the other draft).
 *
 * Two workflow steps prevent that: pre-creating the draft so every publisher
 * attaches to it, and verifying after the build that exactly one release holds
 * all expected artifacts. If either regresses, the split can return silently.
 */
test('release workflow pre-creates the draft before building', function () {
    $workflow = file_get_contents(dirname(__DIR__, 2).'/.github/workflows/release.yml');

    expect($workflow)
        ->toContain('Pre-create draft release')
        ->toContain('gh release create')
        ->toContain('--draft');

    // The pre-create step must run before electron-builder publishes, otherwise
    // there is no existing draft for the publishers to attach to.
    $preCreatePosition = strpos($workflow, 'Pre-create draft release');
    $buildPosition = strpos($workflow, 'native:build mac arm64 --publish');

    expect($preCreatePosition)->toBeLessThan($buildPosition);
});

test('release workflow verifies one release with exactly the expected artifacts', function () {
    $workflow = file_get_contents(dirname(__DIR__, 2).'/.github/workflows/release.yml');

    expect($workflow)
        ->toContain('Verify release artifacts')
        ->toContain('Expected exactly 1 release')
        ->toContain('latest-mac.yml')
        ->toContain('rfa-${version}-arm64.zip')
        ->toContain('rfa-${version}-arm64.zip.blockmap')
        ->toContain('rfa-${version}-arm64.dmg')
        ->toContain('rfa-${version}-arm64.dmg.blockmap')
        // Exact-set check: a stale extra asset must fail the job, not just a missing one.
        ->toContain('Unexpected release assets')
        // --slurp collapses paginated results so the count test can't be bypassed.
        ->toContain('--paginate --slurp');

    // Verification must run after the build so it inspects the published assets.
    $buildPosition = strpos($workflow, 'native:build mac arm64 --publish');
    $verifyPosition = strpos($workflow, 'Verify release artifacts');

    expect($verifyPosition)->toBeGreaterThan($buildPosition);
});

test('release workflow derives the version in-shell to avoid template injection', function () {
    $workflow = file_get_contents(dirname(__DIR__, 2).'/.github/workflows/release.yml');

    // Tag-derived values are expanded in-shell; interpolating ${{ }} into a run
    // block would let a tag like v1.2.3$(...) execute before the shell parses it.
    expect($workflow)
        ->toContain('version="${tag#v}"')
        ->not->toContain('--title "${{ steps.version.outputs.value }}"');
});
