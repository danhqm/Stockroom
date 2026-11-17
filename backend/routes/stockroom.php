<?php

use App\Http\Controllers\CatalogueController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\PurchaseOrderController;
use App\Http\Controllers\ReceiptController;
use Illuminate\Support\Facades\Route;

Route::prefix('api')->middleware(['auth', 'can:receive-stock'])->group(function () {
    // Restrict the table argument to these two literal catalogues.
    Route::get('/{catalogue}', [CatalogueController::class, 'index'])->whereIn('catalogue', ['products', 'suppliers']);
    Route::get('/purchase-orders', [PurchaseOrderController::class, 'index']);
    Route::get('/purchase-orders/{order}', [PurchaseOrderController::class, 'show'])->whereNumber('order');
    Route::get('/receipts', [ReceiptController::class, 'index']);
    Route::post('/receipts', [ReceiptController::class, 'store']);
    Route::get('/receipts/{receipt}', [ReceiptController::class, 'show'])->whereNumber('receipt');
    Route::put('/receipts/{receipt}', [ReceiptController::class, 'update'])->whereNumber('receipt');
    Route::post('/receipts/{receipt}/confirm', [ReceiptController::class, 'confirm'])->whereNumber('receipt');
    Route::get('/inventory', [InventoryController::class, 'index']);
    Route::get('/inventory/{product}/movements', [InventoryController::class, 'movements'])->whereNumber('product');

    Route::middleware('can:manage-stockroom')->group(function () {
        Route::post('/{catalogue}', [CatalogueController::class, 'store'])->whereIn('catalogue', ['products', 'suppliers']);
        Route::put('/{catalogue}/{id}', [CatalogueController::class, 'update'])->whereIn('catalogue', ['products', 'suppliers'])->whereNumber('id');
        Route::post('/purchase-orders', [PurchaseOrderController::class, 'store']);
        Route::put('/purchase-orders/{order}', [PurchaseOrderController::class, 'update'])->whereNumber('order');
        Route::post('/purchase-orders/{order}/issue', [PurchaseOrderController::class, 'issue'])->whereNumber('order');
        Route::post('/purchase-orders/{order}/close', [PurchaseOrderController::class, 'close'])->whereNumber('order');
        Route::post('/receipts/{receipt}/reverse', [ReceiptController::class, 'reverse'])->whereNumber('receipt');
    });
});
