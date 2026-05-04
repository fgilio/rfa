<?php

declare(strict_types=1);

use Livewire\Livewire;
use Native\Desktop\App as NativeApp;
use Tests\TestCase;

uses(TestCase::class);

test('the menu broadcast for quit-rfa fires the quit-prompt-show browser event', function () {
    Livewire::test('quit-confirmation')
        ->dispatch('native:Native\\Desktop\\Events\\Menu\\MenuItemClicked', item: ['id' => 'quit-rfa'])
        ->assertDispatched('quit-prompt-show');
});

test('an unrelated menu broadcast does not fire the quit prompt', function () {
    Livewire::test('quit-confirmation')
        ->dispatch('native:Native\\Desktop\\Events\\Menu\\MenuItemClicked', item: ['id' => 'open-repo'])
        ->assertNotDispatched('quit-prompt-show');
});

test('the quit action invokes NativePHP App::quit()', function () {
    $native = Mockery::mock(NativeApp::class);
    $native->shouldReceive('quit')->once();
    app()->instance(NativeApp::class, $native);

    Livewire::test('quit-confirmation')->call('quit');
});
