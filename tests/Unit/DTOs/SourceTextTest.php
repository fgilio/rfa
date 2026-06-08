<?php

use App\DTOs\FileSourceSpec;
use App\DTOs\SourceText;

test('loaded source text includes content and byte size', function () {
    $source = FileSourceSpec::working('app/Foo.php');
    $text = SourceText::loaded($source, "hello\n");

    expect($text->isLoaded())->toBeTrue()
        ->and($text->toArray())->toBe([
            'source' => $source->toArray(),
            'status' => SourceText::STATUS_LOADED,
            'content' => "hello\n",
            'byteSize' => 6,
            'skipReason' => null,
        ]);
});

test('none source text carries no content', function () {
    $source = FileSourceSpec::none();
    $text = SourceText::none($source);

    expect($text->isLoaded())->toBeFalse()
        ->and($text->toArray())->toMatchArray([
            'source' => $source->toArray(),
            'status' => SourceText::STATUS_NONE,
            'content' => null,
            'byteSize' => null,
            'skipReason' => null,
        ]);
});

test('missing source text can be detected', function () {
    $source = FileSourceSpec::git('HEAD', 'missing.php');
    $text = SourceText::missing($source);

    expect($text->isMissing())->toBeTrue()
        ->and($text->toArray()['status'])->toBe(SourceText::STATUS_MISSING);
});

test('too large source text records the byte size and reason', function () {
    $source = FileSourceSpec::working('big.log');
    $text = SourceText::tooLarge($source, 2048);

    expect($text->isTooLarge())->toBeTrue()
        ->and($text->toArray())->toMatchArray([
            'status' => SourceText::STATUS_TOO_LARGE,
            'content' => null,
            'byteSize' => 2048,
            'skipReason' => 'source-too-large',
        ]);
});
