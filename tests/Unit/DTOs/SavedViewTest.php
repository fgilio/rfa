<?php

use App\DTOs\SavedView;
use App\Enums\LastViewKind;
use App\Enums\LastViewMode;

test('each view kind has exactly one factory that fills its own columns', function (SavedView $view, array $columns) {
    expect($view->toArray())->toBe($columns);
})->with([
    'context' => [fn () => SavedView::context(), [
        'last_view_mode' => LastViewMode::Context,
        'last_view_kind' => null,
        'last_view_from' => null,
        'last_view_to' => null,
    ]],
    'working tree' => [fn () => SavedView::workingTree(), [
        'last_view_mode' => LastViewMode::Review,
        'last_view_kind' => LastViewKind::WorkingTree,
        'last_view_from' => null,
        'last_view_to' => null,
    ]],
    'since base' => [fn () => SavedView::sinceBase(), [
        'last_view_mode' => LastViewMode::Review,
        'last_view_kind' => LastViewKind::SinceBase,
        'last_view_from' => null,
        'last_view_to' => null,
    ]],
    'commit' => [fn () => SavedView::commit('cafe1234'), [
        'last_view_mode' => LastViewMode::Review,
        'last_view_kind' => LastViewKind::Commit,
        'last_view_from' => null,
        'last_view_to' => 'cafe1234',
    ]],
    'range' => [fn () => SavedView::range('aaaa1111', 'bbbb2222'), [
        'last_view_mode' => LastViewMode::Review,
        'last_view_kind' => LastViewKind::Range,
        'last_view_from' => 'aaaa1111',
        'last_view_to' => 'bbbb2222',
    ]],
    'range to working' => [fn () => SavedView::rangeToWorking('aaaa1111'), [
        'last_view_mode' => LastViewMode::Review,
        'last_view_kind' => LastViewKind::RangeToWorking,
        'last_view_from' => 'aaaa1111',
        'last_view_to' => null,
    ]],
]);

test('the commit factory rejects a blank ref', fn () => SavedView::commit(''))
    ->throws(InvalidArgumentException::class, 'The to ref must not be blank.');

test('the range factory rejects a blank start ref', fn () => SavedView::range('   ', 'bbbb'))
    ->throws(InvalidArgumentException::class, 'The from ref must not be blank.');

test('the range factory rejects a blank end ref', fn () => SavedView::range('aaaa', ''))
    ->throws(InvalidArgumentException::class, 'The to ref must not be blank.');

test('the range-to-working factory rejects a blank ref', fn () => SavedView::rangeToWorking(''))
    ->throws(InvalidArgumentException::class, 'The from ref must not be blank.');

test('there is no public constructor to bypass the factories', function () {
    expect((new ReflectionClass(SavedView::class))->getConstructor()->isPrivate())->toBeTrue();
});

test('an empty tuple is the default landing view for its mode', function () {
    expect(SavedView::fromColumns(LastViewMode::Context, null, null, null))->toEqual(SavedView::context())
        ->and(SavedView::fromColumns(LastViewMode::Review, null, null, null))->toEqual(SavedView::workingTree());
});

test('fromColumns round trips every view a factory can produce', function (SavedView $view) {
    $columns = $view->toArray();

    $restored = SavedView::fromColumns(
        $columns['last_view_mode'],
        $columns['last_view_kind'],
        $columns['last_view_from'],
        $columns['last_view_to'],
    );

    expect($restored)->toEqual($view);
})->with([
    'context' => [fn () => SavedView::context()],
    'working tree' => [fn () => SavedView::workingTree()],
    'since base' => [fn () => SavedView::sinceBase()],
    'commit' => [fn () => SavedView::commit('cafe1234')],
    'range' => [fn () => SavedView::range('aaaa1111', 'bbbb2222')],
    'range to working' => [fn () => SavedView::rangeToWorking('aaaa1111')],
]);

test('fromColumns falls back to the working tree for a malformed tuple', function (?LastViewKind $kind, ?string $from, ?string $to) {
    expect(SavedView::fromColumns(LastViewMode::Review, $kind, $from, $to))
        ->toEqual(SavedView::workingTree());
})->with([
    'no kind at all' => [null, null, null],
    'commit without a target' => [LastViewKind::Commit, 'aaaa', null],
    'commit with a blank target' => [LastViewKind::Commit, null, '  '],
    'range missing its end' => [LastViewKind::Range, 'aaaa', null],
    'range missing its start' => [LastViewKind::Range, null, 'bbbb'],
    'range to working without a start' => [LastViewKind::RangeToWorking, null, 'bbbb'],
]);

test('fromColumns drops refs a Context row should never have carried', function () {
    expect(SavedView::fromColumns(LastViewMode::Context, LastViewKind::Range, 'aaaa', 'bbbb'))
        ->toEqual(SavedView::context());
});

test('fromColumns drops the refs a stored SinceBase row should never have carried', function () {
    $view = SavedView::fromColumns(LastViewMode::Review, LastViewKind::SinceBase, 'stale-merge-base', 'HEAD');

    expect($view)->toEqual(SavedView::sinceBase())
        ->and($view->from)->toBeNull()
        ->and($view->to)->toBeNull();
});
