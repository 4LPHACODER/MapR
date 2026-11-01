<?php

use App\Http\Controllers\Auth\LoginController;
use Illuminate\Support\Facades\Route;

require __DIR__.'/landing.php';

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->name('login.store');
});

Route::post('/logout', [LoginController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

require __DIR__.'/resident.php';
require __DIR__.'/bdrrm.php';
require __DIR__.'/assessor.php';
require __DIR__.'/cdrrmo.php';
