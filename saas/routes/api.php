<?php

use App\Http\Controllers\Api\EditRequestController;
use App\Http\Controllers\Api\SiteController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/site')->middleware(['site', 'throttle:60,1'])->group(function () {
    Route::post('check-in', [SiteController::class, 'checkIn']);
    Route::get('usage', [SiteController::class, 'usage']);
});

Route::prefix('v1/site')->middleware(['site', 'throttle:10,1'])->group(function () {
    Route::post('edit-requests', [EditRequestController::class, 'store']);
});
