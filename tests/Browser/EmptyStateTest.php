<?php

beforeEach(function () {
    $this->setUpEmptyTestRepo();
});

test('no changes shows clean working tree message', function () {
    $this->visit($this->projectUrl())
        ->assertSee('Working tree is clean')
        ->assertSee('Edit files to see them here');
});

test('empty state has no file list', function () {
    $this->visit($this->projectUrl())
        ->assertDontSee('hello.php')
        ->assertDontSee('utils.php')
        ->assertDontSee('config.php');
});
