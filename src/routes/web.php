<?php

use App\Livewire\DashboardPage;
use App\Livewire\ReviewPage;
use Illuminate\Support\Facades\Route;

Route::get('/', DashboardPage::class);
Route::get('/p/{slug}', ReviewPage::class);
