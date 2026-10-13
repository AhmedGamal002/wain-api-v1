<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\PlaceController;
use App\Models\Place;
use Illuminate\Support\Facades\Route;

// المسار الأساسي /api/v1 (06 القسم 1). أي تغيير في الـ API يتكتب في Scribe الأول.
Route::prefix('v1')->group(function () {
    Route::get('categories', [CategoryController::class, 'index']);
    Route::get('places', [PlaceController::class, 'index']);
    Route::get('places/nearby', [PlaceController::class, 'nearby']);
    // {slug} بعد المسارات الثابتة (nearby وcount وmap)، والـ where بيحمي لو اتضاف مسار ثابت بعده بالغلط:
    // الـ slug ما يطابقش كلمة محجوزة (Place::RESERVED_SLUGS)، فـ /places/count ما يتفسّرش على إنه مكان.
    Route::get('places/{slug}', [PlaceController::class, 'show'])
        ->where('slug', '(?!(?:'.implode('|', Place::RESERVED_SLUGS).')$)[^/]+');

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
