<?php

use App\Actions\GetCurrentHeadAction;
use App\DTOs\CurrentHeadResult;
use Livewire\Livewire;
use Tests\TestCase;

uses(TestCase::class);

function bindFakeHeadAction(CurrentHeadResult $initial): object
{
    $fake = new class($initial)
    {
        public function __construct(public CurrentHeadResult $result) {}

        public function handle(string $repoPath, ?string $targetBranch = null): CurrentHeadResult
        {
            return $this->result;
        }
    };

    app()->instance(GetCurrentHeadAction::class, $fake);

    return $fake;
}

test('mount primes the fingerprint without dispatching', function () {
    bindFakeHeadAction(new CurrentHeadResult(branch: 'main', sha: 'a'.str_repeat('0', 39), detached: false, targetExists: true));

    $poller = Livewire::test('head-divergence-poller', ['repoPath' => '/tmp/repo', 'target' => 'main']);

    $poller->assertNotDispatched('head-divergence-transitioned');
    expect($poller->get('sha'))->not->toBe('');
    expect($poller->get('identity'))->not->toBe('');
});

test('poll after mount on an unchanged HEAD does not dispatch', function () {
    bindFakeHeadAction(new CurrentHeadResult(branch: 'main', sha: 'a'.str_repeat('0', 39), detached: false, targetExists: true));

    $poller = Livewire::test('head-divergence-poller', ['repoPath' => '/tmp/repo', 'target' => 'main']);

    $poller->call('poll')->assertNotDispatched('head-divergence-transitioned');
});

test('mount does not prime when git is transiently failing', function () {
    bindFakeHeadAction(new CurrentHeadResult(branch: null, sha: '', detached: false));

    $poller = Livewire::test('head-divergence-poller', ['repoPath' => '/tmp/repo', 'target' => 'main']);

    expect($poller->get('sha'))->toBe('');
    expect($poller->get('identity'))->toBe('');
});

test('poll dispatches head-advanced-on-branch when only the SHA changes', function () {
    $fake = bindFakeHeadAction(new CurrentHeadResult(branch: 'main', sha: 'a'.str_repeat('0', 39), detached: false, targetExists: true));

    $poller = Livewire::test('head-divergence-poller', ['repoPath' => '/tmp/repo', 'target' => 'main']);
    $poller->call('poll');

    $fake->result = new CurrentHeadResult(branch: 'main', sha: 'b'.str_repeat('0', 39), detached: false, targetExists: true);

    $poller->call('poll')
        ->assertDispatched('head-advanced-on-branch')
        ->assertNotDispatched('head-divergence-transitioned');
});

test('poll dispatches again when branch changes', function () {
    $fake = bindFakeHeadAction(new CurrentHeadResult(branch: 'main', sha: 'a'.str_repeat('0', 39), detached: false, targetExists: true));

    $poller = Livewire::test('head-divergence-poller', ['repoPath' => '/tmp/repo', 'target' => 'main']);
    $poller->call('poll');

    $fake->result = new CurrentHeadResult(branch: 'feature-x', sha: 'a'.str_repeat('0', 39), detached: false, targetExists: true);

    $poller->call('poll')
        ->assertDispatched('head-divergence-transitioned')
        ->assertNotDispatched('head-advanced-on-branch');
});

test('poll dispatches divergence (not advance) when branch and SHA both change', function () {
    $fake = bindFakeHeadAction(new CurrentHeadResult(branch: 'main', sha: 'a'.str_repeat('0', 39), detached: false, targetExists: true));

    $poller = Livewire::test('head-divergence-poller', ['repoPath' => '/tmp/repo', 'target' => 'main']);
    $poller->call('poll');

    $fake->result = new CurrentHeadResult(branch: 'feature-x', sha: 'b'.str_repeat('0', 39), detached: false, targetExists: true);

    $poller->call('poll')
        ->assertDispatched('head-divergence-transitioned')
        ->assertNotDispatched('head-advanced-on-branch');
});

test('poll dispatches again when detached flag flips', function () {
    $fake = bindFakeHeadAction(new CurrentHeadResult(branch: 'main', sha: 'a'.str_repeat('0', 39), detached: false, targetExists: true));

    $poller = Livewire::test('head-divergence-poller', ['repoPath' => '/tmp/repo', 'target' => 'main']);
    $poller->call('poll');

    $fake->result = new CurrentHeadResult(branch: null, sha: 'a'.str_repeat('0', 39), detached: true, targetExists: true);

    $poller->call('poll')->assertDispatched('head-divergence-transitioned');
});

test('poll dispatches again when targetExists flips', function () {
    $fake = bindFakeHeadAction(new CurrentHeadResult(branch: 'main', sha: 'a'.str_repeat('0', 39), detached: false, targetExists: true));

    $poller = Livewire::test('head-divergence-poller', ['repoPath' => '/tmp/repo', 'target' => 'main']);
    $poller->call('poll');

    $fake->result = new CurrentHeadResult(branch: 'main', sha: 'a'.str_repeat('0', 39), detached: false, targetExists: false);

    $poller->call('poll')->assertDispatched('head-divergence-transitioned');
});

test('first successful poll after a sentinel mount dispatches head-advanced-on-branch', function () {
    // Scenario: GetCurrentHeadAction returns the sentinel during mount (mid-rebase
    // or lock contention). A commit may have landed during the transient window,
    // so the file list captured at mount is potentially stale. Surfacing this as
    // head-advanced-on-branch routes through softRefresh, which re-reads files
    // AND recomputes divergence. Dispatching only head-divergence-transitioned
    // would leave the diff stuck at the pre-recovery snapshot for same-branch
    // HEADs (the page sees no banner change and skipRenders).
    $fake = bindFakeHeadAction(new CurrentHeadResult(branch: null, sha: '', detached: false));

    $poller = Livewire::test('head-divergence-poller', ['repoPath' => '/tmp/repo', 'target' => 'main']);
    expect($poller->get('sha'))->toBe('');

    $fake->result = new CurrentHeadResult(branch: 'main', sha: 'a'.str_repeat('0', 39), detached: false, targetExists: true);

    $poller->call('poll')
        ->assertDispatched('head-advanced-on-branch')
        ->assertNotDispatched('head-divergence-transitioned');
});

test('sentinel result (empty sha) does not dispatch and does not advance fingerprint', function () {
    $fake = bindFakeHeadAction(new CurrentHeadResult(branch: 'main', sha: 'a'.str_repeat('0', 39), detached: false, targetExists: true));

    $poller = Livewire::test('head-divergence-poller', ['repoPath' => '/tmp/repo', 'target' => 'main']);
    $poller->call('poll');

    $establishedSha = $poller->get('sha');
    $establishedIdentity = $poller->get('identity');

    // Simulate transient git failure (mid-rebase, lock contention).
    $fake->result = new CurrentHeadResult(branch: null, sha: '', detached: false);
    $poller->call('poll')->assertNotDispatched('head-divergence-transitioned');

    expect($poller->get('sha'))->toBe($establishedSha);
    expect($poller->get('identity'))->toBe($establishedIdentity);
});
