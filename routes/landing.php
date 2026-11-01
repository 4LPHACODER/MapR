<?php

use App\Http\Controllers\Landing\HomeController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');
