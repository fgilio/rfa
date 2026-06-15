<?php

use App\DTOs\DiffTarget;
use App\Support\DiffCacheKey;

test('workingDirectory builds a HEAD → working-tree target', function () {
    $target = DiffTarget::workingDirectory();

    expect($target->from())->toBe('HEAD')
        ->and($target->to())->toBeNull()
        ->and($target->isWorkingDirectory())->toBeTrue()
        ->and($target->isImmutable())->toBeFalse();
});

test('commit derives from empty-tree when no parent is provided', function () {
    $target = DiffTarget::commit('abc123');

    expect($target->from())->toBe(DiffTarget::EMPTY_TREE_HASH)
        ->and($target->to())->toBe('abc123');
});

test('range keeps both endpoints verbatim', function () {
    $target = DiffTarget::range('from-sha', 'to-sha');

    expect($target->from())->toBe('from-sha')
        ->and($target->to())->toBe('to-sha')
        ->and($target->isImmutable())->toBeTrue();
});

test('rangeToWorking preserves the from commit with a null to', function () {
    $target = DiffTarget::rangeToWorking('abc123');

    expect($target->from())->toBe('abc123')
        ->and($target->to())->toBeNull()
        ->and($target->isWorkingDirectory())->toBeTrue()
        ->and($target->isImmutable())->toBeFalse();
});

test('fromRefs preserves the from commit when to is null', function () {
    // Regression: fromRefs($from, null) used to collapse to workingDirectory(),
    // forcing per-file diffs in range-to-working views to run against HEAD
    // and silently rendering "No content changes" for committed files.
    $target = DiffTarget::fromRefs('abc123', null);

    expect($target->from())->toBe('abc123')
        ->and($target->to())->toBeNull()
        ->and($target->toDiffArgs())->toBe(['diff', 'abc123']);
});

test('toDiffArgs emits single-arg diff for range-to-working', function () {
    expect(DiffTarget::rangeToWorking('abc123')->toDiffArgs())->toBe(['diff', 'abc123'])
        ->and(DiffTarget::workingDirectory()->toDiffArgs())->toBe(['diff', 'HEAD'])
        ->and(DiffTarget::range('from', 'to')->toDiffArgs())->toBe(['diff', 'from', 'to']);
});

test('cacheTtlHours caps immutable targets and passes the configured TTL through for working-tree targets', function () {
    expect(DiffTarget::range('from', 'to')->cacheTtlHours(24))->toBe(720)
        // Immutable targets short-circuit, so the arg is optional for callers
        // (e.g. SFCs) that can't supply the coerced TTL.
        ->and(DiffTarget::range('from', 'to')->cacheTtlHours())->toBe(720)
        ->and(DiffTarget::workingDirectory()->cacheTtlHours(24))->toBe(24)
        ->and(DiffTarget::rangeToWorking('abc123')->cacheTtlHours(12))->toBe(12);
});

test('contextKey disambiguates working-tree targets by their from ref', function () {
    $workingFromHead = DiffTarget::workingDirectory()->contextKey();
    $workingFromCommit = DiffTarget::rangeToWorking('abc123')->contextKey();

    expect($workingFromHead)->toBe('HEAD..working')
        ->and($workingFromCommit)->toBe('abc123..working')
        ->and($workingFromHead)->not->toBe($workingFromCommit);
});

test('DiffCacheKey produces distinct keys for HEAD→WT vs commit→WT', function () {
    $keyHead = DiffCacheKey::for('repo', 'file-1', DiffTarget::workingDirectory()->contextKey());
    $keyCommit = DiffCacheKey::for('repo', 'file-1', DiffTarget::rangeToWorking('abc123')->contextKey());

    expect($keyHead)->not->toBe($keyCommit);
});

test('DiffCacheKey default matches workingDirectory contextKey', function () {
    $defaultKey = DiffCacheKey::for('repo', 'file-1');
    $explicitKey = DiffCacheKey::for('repo', 'file-1', DiffTarget::workingDirectory()->contextKey());

    expect($defaultKey)->toBe($explicitKey);
});
