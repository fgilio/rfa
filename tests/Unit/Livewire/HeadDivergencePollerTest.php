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
    expect($poller->get('fingerprint'))->not->toBe('');
});

test('poll after mount on an unchanged HEAD does not dispatch', function () {
    bindFakeHeadAction(new CurrentHeadResult(branch: 'main', sha: 'a'.str_repeat('0', 39), detached: false, targetExists: true));

    $poller = Livewire::test('head-divergence-poller', ['repoPath' => '/tmp/repo', 'target' => 'main']);

    $poller->call('poll')->assertNotDispatched('head-divergence-transitioned');
});

test('mount does not prime when git is transiently failing', function () {
    bindFakeHeadAction(new CurrentHeadResult(branch: null, sha: '', detached: false));

    $poller = Livewire::test('head-divergence-poller', ['repoPath' => '/tmp/repo', 'target' => 'main']);

    expect($poller->get('fingerprint'))->toBe('');
});

test('poll dispatches again when SHA changes', function () {
    $fake = bindFakeHeadAction(new CurrentHeadResult(branch: 'main', sha: 'a'.str_repeat('0', 39), detached: false, targetExists: true));

    $poller = Livewire::test('head-divergence-poller', ['repoPath' => '/tmp/repo', 'target' => 'main']);
    $poller->call('poll');

    $fake->result = new CurrentHeadResult(branch: 'main', sha: 'b'.str_repeat('0', 39), detached: false, targetExists: true);

    $poller->call('poll')->assertDispatched('head-divergence-transitioned');
});

test('poll dispatches again when branch changes', function () {
    $fake = bindFakeHeadAction(new CurrentHeadResult(branch: 'main', sha: 'a'.str_repeat('0', 39), detached: false, targetExists: true));

    $poller = Livewire::test('head-divergence-poller', ['repoPath' => '/tmp/repo', 'target' => 'main']);
    $poller->call('poll');

    $fake->result = new CurrentHeadResult(branch: 'feature-x', sha: 'a'.str_repeat('0', 39), detached: false, targetExists: true);

    $poller->call('poll')->assertDispatched('head-divergence-transitioned');
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

test('sentinel result (empty sha) does not dispatch and does not advance fingerprint', function () {
    $fake = bindFakeHeadAction(new CurrentHeadResult(branch: 'main', sha: 'a'.str_repeat('0', 39), detached: false, targetExists: true));

    $poller = Livewire::test('head-divergence-poller', ['repoPath' => '/tmp/repo', 'target' => 'main']);
    $poller->call('poll');

    $established = $poller->get('fingerprint');

    // Simulate transient git failure (mid-rebase, lock contention).
    $fake->result = new CurrentHeadResult(branch: null, sha: '', detached: false);
    $poller->call('poll')->assertNotDispatched('head-divergence-transitioned');

    expect($poller->get('fingerprint'))->toBe($established);
});
