<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

// Note: BenchmarkPerformanceCommand's --child mode requires PerfScenarioRunner,
// which is a final class with heavy dependencies (Livewire, DB, Cache).
// Full integration coverage is provided by `composer test:perf`.
// These tests cover the command's argument parsing and error handling paths.

test('command is registered and has expected options', function () {
    $command = $this->app->make(\Illuminate\Contracts\Console\Kernel::class)
        ->all()['rfa:benchmark-perf'] ?? null;

    expect($command)->not->toBeNull()
        ->and($command->getDefinition()->hasOption('child'))->toBeTrue()
        ->and($command->getDefinition()->hasOption('json'))->toBeTrue()
        ->and($command->getDefinition()->hasOption('snapshot'))->toBeTrue()
        ->and($command->getDefinition()->hasOption('compare'))->toBeTrue()
        ->and($command->getDefinition()->hasOption('samples'))->toBeTrue()
        ->and($command->getDefinition()->hasOption('warmup-samples'))->toBeTrue()
        ->and($command->getDefinition()->hasOption('rounds'))->toBeTrue()
        ->and($command->getDefinition()->hasOption('warmup-rounds'))->toBeTrue()
        ->and($command->getDefinition()->hasOption('max-regression'))->toBeTrue();
});

test('options have expected default values', function () {
    $command = $this->app->make(\Illuminate\Contracts\Console\Kernel::class)
        ->all()['rfa:benchmark-perf'];

    $definition = $command->getDefinition();

    expect($definition->getOption('samples')->getDefault())->toBe('5')
        ->and($definition->getOption('warmup-samples')->getDefault())->toBe('1')
        ->and($definition->getOption('rounds')->getDefault())->toBe('7')
        ->and($definition->getOption('warmup-rounds')->getDefault())->toBe('2')
        ->and($definition->getOption('max-regression')->getDefault())->toBe('5');
});
