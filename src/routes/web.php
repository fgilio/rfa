<?php

use Illuminate\Support\Facades\Route;

Route::livewire('/', 'pages::dashboard-page');
Route::livewire('/p/{slug}', 'pages::review-page');
