<?php

use App\Http\Controllers\Admin\AccountController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\LedgerController;
use App\Http\Controllers\Admin\LoginController;
use App\Http\Controllers\Admin\SiteController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

Route::prefix('admin')->name('admin.')->group(function () {
    Route::middleware('guest')->group(function () {
        Route::get('login', [LoginController::class, 'create'])->name('login');
        Route::post('login', [LoginController::class, 'store'])->middleware('throttle:20,1');
    });

    Route::middleware(['auth', 'admin'])->group(function () {
        Route::post('logout', [LoginController::class, 'destroy'])->name('logout');
        Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
        Route::get('ledger', [LedgerController::class, 'index'])->name('ledger');
        Route::post('accounts', [AccountController::class, 'store'])->name('accounts.store');
        Route::get('accounts/{account}', [AccountController::class, 'show'])->name('accounts.show');
        Route::put('accounts/{account}', [AccountController::class, 'update'])->name('accounts.update');
        Route::post('accounts/{account}/adjustments', [AccountController::class, 'adjust'])->name('accounts.adjust');
        Route::post('accounts/{account}/sites', [SiteController::class, 'store'])->name('sites.store');
        Route::post('sites/{site}/token', [SiteController::class, 'rotate'])->name('sites.rotate');
    });
});
