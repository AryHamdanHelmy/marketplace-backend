<?php

use App\Http\Controllers\AddressController;
use App\Http\Controllers\AdminWithdrawalController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ProductCategoryController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductImportController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SellerBalanceController;
use App\Http\Controllers\SellerStatsController;
use App\Http\Controllers\StoreController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post('auth/register', [AuthController::class, 'register'])->middleware('throttle:5,1');
Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
Route::post('auth/check-email', [AuthController::class, 'checkEmail'])->middleware('throttle:10,1');
Route::post('auth/forgot-password', [PasswordResetController::class, 'sendResetLink'])->middleware('throttle:5,1');
Route::post('auth/reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:5,1');

// Publik
Route::get('/products', [ProductController::class, 'index']);
Route::get('/products/{product}', [ProductController::class, 'show']);
Route::get('/categories', [ProductCategoryController::class, 'index']);
Route::get('/categories/{categories}', [ProductCategoryController::class, 'show']);
Route::get('/shops', [StoreController::class, 'publicIndex']);
Route::get('/shops/{store}', [StoreController::class, 'publicShow']);
Route::get('/shops/{store}', [StoreController::class, 'publicShow']);
Route::post('payment/webhook/{gateway}', [PaymentController::class, 'webhook']);
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
    Route::get('/seller/store', [StoreController::class, 'show']);
    Route::put('/seller/store', [StoreController::class, 'update']);
    Route::put('/seller/store/payout', [StoreController::class, 'updatePayout']);
    Route::patch('/seller/store/status', [StoreController::class, 'toggleStatus']);
    Route::get('/admin/withdrawals', [AdminWithdrawalController::class, 'index']);
    Route::get('/admin/withdrawals/{id}', [AdminWithdrawalController::class, 'show']);
    Route::patch('/admin/withdrawals/{id}/processing', [AdminWithdrawalController::class, 'markProcessing']);
    Route::patch('/admin/withdrawals/{id}/complete', [AdminWithdrawalController::class, 'complete']);
    Route::patch('/admin/withdrawals/{id}/reject', [AdminWithdrawalController::class, 'reject']);

    // Buyer
    Route::get('/orders', [OrderController::class, 'index']);
    Route::get('/orders/{id}', [OrderController::class, 'show']);
    Route::post('/orders/{id}/pay', [OrderController::class, 'pay']);
    Route::post('/orders/{id}/cancel', [OrderController::class, 'cancel']);
    Route::post('/orders/{id}/confirm', [OrderController::class, 'confirmReceipt']);
    Route::get('/profile', [ProfileController::class, 'show']);
    Route::put('/profile', [ProfileController::class, 'update']);
    Route::put('/profile/password', [ProfileController::class, 'updatePassword'])->middleware('throttle:5,1');

    // Seller
    Route::get('/seller/stats', [SellerStatsController::class, 'index']);
    Route::get('/seller/orders', [OrderController::class, 'sellerOrders']);
    Route::put('/seller/orders/{id}/status', [OrderController::class, 'updateStatus']);
    Route::get('/seller/products', [ProductController::class, 'index']);
    Route::post('/categories', [ProductCategoryController::class, 'store']);
    Route::put('/categories/{categories}', [ProductCategoryController::class, 'update']);
    Route::delete('/categories/{categories}', [ProductCategoryController::class, 'destroy']);
    Route::get('/product-import/template', [ProductImportController::class, 'downloadTemplate']);
    Route::post('/product-import/preview',  [ProductImportController::class, 'preview']);
    Route::post('/product-import',          [ProductImportController::class, 'store']);
    Route::get('/product-import/history',   [ProductImportController::class, 'history']);

    Route::get('/seller/balance', [SellerBalanceController::class, 'show']);
    Route::get('/seller/balance/history', [SellerBalanceController::class, 'history']);
    Route::get('/seller/withdrawals', [SellerBalanceController::class, 'index']);
    Route::post('/seller/withdrawals', [SellerBalanceController::class, 'store'])->middleware('throttle:5,1');

    Route::get('/seller/store', [StoreController::class, 'show']);
    Route::put('/seller/store', [StoreController::class, 'update']);
    Route::put('/seller/store/payout', [StoreController::class, 'updatePayout']);
    Route::patch('/seller/store/status', [StoreController::class, 'toggleStatus']);

    Route::get('/addresses', [AddressController::class, 'index']);
    Route::post('/addresses', [AddressController::class, 'store']);
    Route::put('/addresses/{id}', [AddressController::class, 'update']);
    Route::patch('/addresses/{id}/default', [AddressController::class, 'setDefault']);
    Route::delete('/addresses/{id}', [AddressController::class, 'destroy']);

    Route::get('/payments/channels', [PaymentController::class, 'channels']);
    Route::get('/payments/{checkoutGroupId}', [PaymentController::class, 'show']);
    Route::post('/payments/{checkoutGroupId}/charge', [PaymentController::class, 'charge']);
    Route::post('/payments/{checkoutGroupId}/refresh', [PaymentController::class, 'refresh'])->middleware('throttle:10,1');
});