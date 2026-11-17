<?php

use App\Http\Controllers\ExtractionController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/receipts/{receipt}')->where(['receipt' => '[0-9]+'])->middleware(['auth', 'can:receive-stock'])->group(function () {
    Route::post('/document', [ExtractionController::class, 'store'])->middleware('throttle:10,1');
    Route::get('/document', [ExtractionController::class, 'document']);
    Route::get('/extraction', [ExtractionController::class, 'show']);
});
