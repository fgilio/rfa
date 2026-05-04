<?php

declare(strict_types=1);

use App\Actions\QuitAppAction;
use Native\Desktop\App as NativeApp;
use Tests\TestCase;

uses(TestCase::class);

test('handle() forwards to the NativePHP App quit method', function () {
    $native = Mockery::mock(NativeApp::class);
    $native->shouldReceive('quit')->once();
    app()->instance(NativeApp::class, $native);

    app(QuitAppAction::class)->handle();
});
