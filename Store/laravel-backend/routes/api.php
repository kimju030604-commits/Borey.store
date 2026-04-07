<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\Admin\AuthController;
use App\Http\Controllers\Api\Admin\StatsController;
use App\Http\Controllers\Api\Admin\ProductAdminController;
use App\Http\Controllers\Api\Admin\InvoiceAdminController;
use App\Http\Controllers\Api\Admin\CodeController;

/*
|--------------------------------------------------------------------------
| Public API Routes
|--------------------------------------------------------------------------
*/
Route::get('/products', [ProductController::class, 'index']);

Route::get('/invoice',  [InvoiceController::class, 'show']);
Route::post('/invoice', [InvoiceController::class, 'store']);

Route::post('/payment/generate-khqr',  [PaymentController::class, 'generateKhqr']);
Route::post('/payment/check',          [PaymentController::class, 'checkPayment']);
Route::post('/payment/upload-receipt', [PaymentController::class, 'uploadReceipt']);

/*
|--------------------------------------------------------------------------
| Admin Routes  —  session started on every admin request
|--------------------------------------------------------------------------
*/
Route::prefix('admin')
    ->middleware([\Illuminate\Session\Middleware\StartSession::class])
    ->group(function () {

        // Auth (no admin.auth guard)
        Route::post('/auth/login',  [AuthController::class, 'login']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/auth/check',   [AuthController::class, 'check']);

        // Protected admin routes
        Route::middleware(['admin.auth'])->group(function () {
            Route::get('/stats', [StatsController::class, 'index']);

            Route::get('/products',              [ProductAdminController::class, 'index']);
            Route::post('/products',             [ProductAdminController::class, 'store']);
            Route::post('/products/update-stock', [ProductAdminController::class, 'updateStock']);
            Route::delete('/products/{id}',      [ProductAdminController::class, 'destroy']);

            Route::get('/invoices',              [InvoiceAdminController::class, 'index']);
            Route::delete('/invoices/{id}',      [InvoiceAdminController::class, 'destroy']);
            Route::post('/invoices/regenerate-pdf', [InvoiceAdminController::class, 'regeneratePdf']);

            Route::get('/codes',                 [CodeController::class, 'index']);
            Route::post('/codes/generate',       [CodeController::class, 'generate']);
            Route::post('/codes/deactivate',     [CodeController::class, 'deactivate']);
        });
    });
