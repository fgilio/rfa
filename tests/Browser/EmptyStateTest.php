<?php

beforeEach(function () {
    $this->setUpEmptyTestRepo();
});

test('no changes shows no changes detected message', function () {
    $this->visit($this->projectUrl())
        ->assertSee('No changes detected')
        ->assertSee('Make some changes and run rfa again');
});

test('empty state has no file list', function () {
    $this->visit($this->projectUrl())
        ->assertDontSee('hello.php')
        ->assertDontSee('utils.php')
        ->assertDontSee('config.php');
});
