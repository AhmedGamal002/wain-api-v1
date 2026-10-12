<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CategoryController;
use Illuminate\Support\Facades\Route;

// المسار الأساسي /api/v1 (06 القسم 1). أي تغيير في الـ API يتكتب في Scribe الأول.
Route::prefix('v1')->group(function () {
    Route::get('categories', [CategoryController::class, 'index']);

    Route::prefix('auth')->group(function () {
        // 5 محاولات في الدقيقة لكل IP + إيميل (SEC-01)
        Route::post('register', [AuthController::class, 'register'])->middleware('throttle:auth');
        Route::post('login', [AuthController::class, 'login'])->middleware('throttle:auth');

        Route::middleware('auth:sanctum')->group(function () {
            Route::post('logout', [AuthController::class, 'logout']);
            Route::get('me', [AuthController::class, 'me']);
        });
    });
});
