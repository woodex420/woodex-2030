<?php

use App\Http\Controllers\InstallController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web installer (docs/06 §2, D-58)
|--------------------------------------------------------------------------
| Registered WITHOUT the "web" middleware group: before installation there is no APP_KEY (cookies cannot be
| encrypted) and no sessions table. Every POST carries the setup code instead. After installation these URLs 404.
*/
Route::get('/install', [InstallController::class, 'show'])->name('install');
Route::post('/install', [InstallController::class, 'store'])->name('install.store');
Route::post('/install/finish', [InstallController::class, 'finish'])->name('install.finish');
