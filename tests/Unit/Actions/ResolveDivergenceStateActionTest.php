<?php

use App\Actions\ResolveDivergenceStateAction;
use App\DTOs\CurrentHeadResult;
use App\Enums\DivergenceDecisionKind;
use App\Enums\DivergenceState;

beforeEach(function () {
    $this->action = new ResolveDivergenceStateAction;

    // Default comment lookups that record whether they ran, so tests can prove
    // the action stays lazy on the branches that should never touch the DB.
    $this->hasCommentsCalls = 0;
    $this->countCalls = 0;
    $this->hasComments = function () {
        $this->hasCommentsCalls++;

        return true;
    };
    $this->commentCount = function () {
        $this->countCalls++;

        return 3;
    };

    $this->resolve = fn (CurrentHeadResult $head, string $branch = 'main', ?string $dismissedAtHead = null, ?string $dismissedAtBranch = null, bool $isInitialResolve = false) => $this->action->handle(
        $head,
        $branch,
        $dismissedAtHead,
        $dismissedAtBranch,
        $this->hasComments,
        $this->commentCount,
        $isInitialResolve,
    );
});

// -- transient failure --

test('returns noop on the empty-sha sentinel without touching comment lookups', function () {
    $decision = ($this->resolve)(new CurrentHeadResult(branch: null, sha: '', detached: false));

    expect($decision->kind)->toBe(DivergenceDecisionKind::Noop)
        ->and($decision->state)->toBeNull()
        ->and($this->hasCommentsCalls)->toBe(0)
        ->and($this->countCalls)->toBe(0);
});

// -- aligned --

test('returns aligned when HEAD is on the reviewed branch and queries no comments', function () {
    $decision = ($this->resolve)(new CurrentHeadResult(branch: 'main', sha: 'abc123', detached: false));

    expect($decision->kind)->toBe(DivergenceDecisionKind::Aligned)
        ->and($this->hasCommentsCalls)->toBe(0)
        ->and($this->countCalls)->toBe(0);
});

// -- detached --

test('surfaces a detached banner with a short sha and no current branch', function () {
    $decision = ($this->resolve)(new CurrentHeadResult(branch: null, sha: 'deadbeefcafe', detached: true));

    expect($decision->kind)->toBe(DivergenceDecisionKind::Show)
        ->and($decision->state)->toBe(DivergenceState::Detached)
        ->and($decision->context)->toBe([
            'target' => 'main',
            'currentBranch' => null,
            'currentSha' => 'deadbeefcafe',
            'shortSha' => 'deadbee',
        ]);
});

test('aligns a detached HEAD the user already dismissed at this sha', function () {
    $decision = ($this->resolve)(
        new CurrentHeadResult(branch: null, sha: 'deadbeef', detached: true),
        'main',
        dismissedAtHead: 'deadbeef',
    );

    expect($decision->kind)->toBe(DivergenceDecisionKind::Aligned);
});

// -- missing target --

test('surfaces a missing-target banner when the reviewed branch no longer exists', function () {
    $decision = ($this->resolve)(
        new CurrentHeadResult(branch: 'feature', sha: 'abc1234', detached: false, targetExists: false),
    );

    expect($decision->kind)->toBe(DivergenceDecisionKind::Show)
        ->and($decision->state)->toBe(DivergenceState::MissingTarget)
        ->and($decision->context)->toBe([
            'target' => 'main',
            'currentBranch' => 'feature',
            'currentSha' => 'abc1234',
            'shortSha' => 'abc1234',
        ]);
});

test('auto-follows HEAD on the initial resolve when the reviewed branch is gone', function () {
    $decision = ($this->resolve)(
        new CurrentHeadResult(branch: 'feature', sha: 'abc1234', detached: false, targetExists: false),
        'main',
        isInitialResolve: true,
    );

    // A fresh open lands on the checked-out branch rather than the blocking
    // banner — the stored target is gone and can't be reviewed anyway.
    expect($decision->kind)->toBe(DivergenceDecisionKind::AutoFollow)
        ->and($decision->autoFollowBranch)->toBe('feature')
        ->and($this->hasCommentsCalls)->toBe(0)
        ->and($this->countCalls)->toBe(0);
});

test('keeps the recoverable banner on the initial resolve when branch existence is unverifiable', function () {
    // targetExists === null means the existence probe couldn't complete (a
    // transient git failure), not a confirmed deletion. The initial-resolve
    // auto-follow must NOT fire here: silently persisting a retarget could
    // strand the user off a branch that still exists.
    $decision = ($this->resolve)(
        new CurrentHeadResult(branch: 'feature', sha: 'abc1234', detached: false, targetExists: null),
        'main',
        isInitialResolve: true,
    );

    expect($decision->kind)->toBe(DivergenceDecisionKind::Show)
        ->and($decision->state)->toBe(DivergenceState::MissingTarget);
});

test('surfaces the missing-target banner on a poll tick when existence is unverifiable', function () {
    $decision = ($this->resolve)(
        new CurrentHeadResult(branch: 'feature', sha: 'abc1234', detached: false, targetExists: null),
        'main',
    );

    expect($decision->kind)->toBe(DivergenceDecisionKind::Show)
        ->and($decision->state)->toBe(DivergenceState::MissingTarget);
});

test('a missing target dismissed by branch identity wins over the initial-resolve auto-follow', function () {
    $decision = ($this->resolve)(
        new CurrentHeadResult(branch: 'feature', sha: 'abc1234', detached: false, targetExists: false),
        'main',
        dismissedAtBranch: 'feature',
        isInitialResolve: true,
    );

    expect($decision->kind)->toBe(DivergenceDecisionKind::Aligned);
});

test('aligns a missing target the user dismissed by branch identity', function () {
    $decision = ($this->resolve)(
        new CurrentHeadResult(branch: 'feature', sha: 'abc1234', detached: false, targetExists: false),
        'main',
        dismissedAtBranch: 'feature',
    );

    expect($decision->kind)->toBe(DivergenceDecisionKind::Aligned);
});

// -- auto-follow vs diverged --

test('auto-follows HEAD when no comments are at risk and never counts them', function () {
    $this->hasComments = function () {
        $this->hasCommentsCalls++;

        return false;
    };

    $decision = ($this->resolve)(
        new CurrentHeadResult(branch: 'feature', sha: 'abc1234', detached: false, targetExists: true),
    );

    expect($decision->kind)->toBe(DivergenceDecisionKind::AutoFollow)
        ->and($decision->autoFollowBranch)->toBe('feature')
        ->and($this->hasCommentsCalls)->toBe(1)
        ->and($this->countCalls)->toBe(0);
});

test('surfaces a diverged banner with the comment count when comments exist', function () {
    $decision = ($this->resolve)(
        new CurrentHeadResult(branch: 'feature', sha: 'abc1234', detached: false, targetExists: true),
    );

    expect($decision->kind)->toBe(DivergenceDecisionKind::Show)
        ->and($decision->state)->toBe(DivergenceState::Diverged)
        ->and($decision->context)->toBe([
            'target' => 'main',
            'currentBranch' => 'feature',
            'currentSha' => 'abc1234',
            'shortSha' => 'abc1234',
            'commentCount' => 3,
        ])
        ->and($this->countCalls)->toBe(1);
});

test('aligns a diverged branch the user dismissed without counting comments', function () {
    $decision = ($this->resolve)(
        new CurrentHeadResult(branch: 'feature', sha: 'abc1234', detached: false, targetExists: true),
        'main',
        dismissedAtBranch: 'feature',
    );

    expect($decision->kind)->toBe(DivergenceDecisionKind::Aligned)
        ->and($this->countCalls)->toBe(0);
});

// -- serialization --

test('toArray serializes the discriminant, state, branch, and context', function () {
    $decision = ($this->resolve)(
        new CurrentHeadResult(branch: 'feature', sha: 'abc1234', detached: false, targetExists: true),
    );

    expect($decision->toArray())->toBe([
        'kind' => 'Show',
        'state' => 'diverged',
        'autoFollowBranch' => null,
        'context' => [
            'target' => 'main',
            'currentBranch' => 'feature',
            'currentSha' => 'abc1234',
            'shortSha' => 'abc1234',
            'commentCount' => 3,
        ],
    ]);
});
