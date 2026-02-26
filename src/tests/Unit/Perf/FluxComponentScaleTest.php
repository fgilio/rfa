<?php

use Illuminate\Support\Facades\Blade;

// Post-Blaze thresholds (~1.5x measured baseline) - permanent regression guards
const THRESHOLD_500_MIXED = 55.0;
const THRESHOLD_2000_MIXED = 35.0;
const THRESHOLD_500_NESTED = 45.0;

function renderBladeAndMeasure(string $template, array $data = []): float
{
    $start = hrtime(true);
    Blade::render($template, $data);

    return (hrtime(true) - $start) / 1_000_000;
}

// -- Flux anonymous component scale benchmarks --

test('500 mixed flux components render within threshold', function () {
    $ms = renderBladeAndMeasure(
        '@for($i = 0; $i < $count; $i++) <flux:badge size="sm">Badge {{ $i }}</flux:badge> <flux:icon name="check" variant="mini" /> <flux:text>Text {{ $i }}</flux:text> @endfor',
        ['count' => 500],
    );

    expect($ms)->toRenderWithin(THRESHOLD_500_MIXED);
})->group('perf');

test('2000 mixed flux components render within threshold', function () {
    $ms = renderBladeAndMeasure(
        '@for($i = 0; $i < $count; $i++) <flux:badge size="sm">Badge {{ $i }}</flux:badge> <flux:icon name="check" variant="mini" /> <flux:text>Text {{ $i }}</flux:text> @endfor',
        ['count' => 2000],
    );

    expect($ms)->toRenderWithin(THRESHOLD_2000_MIXED);
})->group('perf');

test('500 nested flux component trees render within threshold', function () {
    $ms = renderBladeAndMeasure(
        '@for($i = 0; $i < $count; $i++) <flux:tooltip content="Tooltip {{ $i }}"><flux:button size="sm"><flux:icon name="star" variant="mini" /> Action {{ $i }}</flux:button></flux:tooltip> @endfor',
        ['count' => 500],
    );

    expect($ms)->toRenderWithin(THRESHOLD_500_NESTED);
})->group('perf');
