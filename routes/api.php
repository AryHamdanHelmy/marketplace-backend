<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ProductCategoryController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\ProductImportController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
Route::post('auth/register', [AuthController::class, 'register'])->middleware('throttle:5,1');
Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
Route::post('auth/check-email', [AuthController::class, 'checkEmail'])->middleware('throttle:10,1');

// Publik
Route::get('/products', [ProductController::class, 'index']);
Route::get('/products/{product}', [ProductController::class, 'show']);
Route::get('/categories', [ProductCategoryController::class, 'index']);
Route::get('/categories/{categories}', [ProductCategoryController::class, 'show']);
Route::get('/debug-ip', function (\Illuminate\Http\Request $request) {
    return response()->json([
        'ip'              => $request->ip(),
        'ips'             => $request->ips(),
        'x_forwarded_for' => $request->header('X-Forwarded-For'),
        'remote_addr'     => $request->server('REMOTE_ADDR'),
    ]);
});

// Protected
Route::middleware('auth:sanctum')->group(function(){
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('/users', [AuthController::class, 'index']);
    Route::delete('/users/{id}', [AuthController::class, 'destroy']);
    Route::get('/users/{id}', [AuthController::class, 'show']);
    Route::put('/user/{id}/role', [AuthController::class, 'updateRole']);
    Route::post('/products', [ProductController::class, 'store']);
    Route::put('/products/{product}', [ProductController::class, 'update']);
    Route::delete('/products/{product}', [ProductController::class, 'destroy']);
    Route::get('/cart', [CartController::class, 'index']);
    Route::post('/cart', [CartController::class, 'store']);
    Route::put('/cart/{id}', [CartController::class, 'update']);
    Route::delete('/cart/{id}', [CartController::class, 'destroy']);
    Route::post('/checkout', [CheckoutController::class, 'store']);
    Route::get('/checkout/{groupId}', [CheckoutController::class, 'showGroup']);
    // Buyer
    Route::get('/orders', [OrderController::class, 'index']);
    Route::get('/orders/{id}', [OrderController::class, 'show']);
    Route::post('/orders/{id}/pay', [OrderController::class, 'pay']);
    Route::post('/orders/{id}/cancel', [OrderController::class, 'cancel']);

    // Seller
    Route::get('/seller/orders', [OrderController::class, 'sellerOrders']);
    Route::put('/seller/orders/{id}/status', [OrderController::class, 'updateStatus']);
    Route::post('/categories', [ProductCategoryController::class, 'store']);
    Route::put('/categories/{categories}', [ProductCategoryController::class, 'update']);
    Route::delete('/categories/{categories}', [ProductCategoryController::class, 'destroy']);
    Route::get('/product-import/template', [ProductImportController::class, 'downloadTemplate']);
    Route::post('/product-import/preview',  [ProductImportController::class, 'preview']);
    Route::post('/product-import',          [ProductImportController::class, 'store']);
    Route::get('/product-import/history',   [ProductImportController::class, 'history']);
});

