<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Pest\Browser\Browsable;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, Browsable::class)
    ->in('Browser');
