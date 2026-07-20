<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ProductCategoryController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\CartController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
Route::post('auth/register', [AuthController::class, 'register']);
Route::post('auth/login', [AuthController::class, 'login']);
Route::post('auth/check-email', [AuthController::class, 'checkEmail']);

// Publik
Route::get('/products', [ProductController::class, 'index']);
Route::get('/products/{product}', [ProductController::class, 'show']);
Route::get('/categories', [ProductCategoryController::class, 'index']);
Route::get('/categories/{categories}', [ProductCategoryController::class, 'show']);

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
    Route::post('/categories', [ProductCategoryController::class, 'store']);
    Route::put('/categories/{categories}', [ProductCategoryController::class, 'update']);
    Route::delete('/categories/{categories}', [ProductCategoryController::class, 'destroy']);
});

