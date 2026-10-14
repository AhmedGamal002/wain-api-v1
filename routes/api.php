<?php

use App\Http\Controllers\Api\V1\AreaController;
use App\Http\Controllers\Api\V1\AttributeController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\ConfigController;
use App\Http\Controllers\Api\V1\EmailVerificationController;
use App\Http\Controllers\Api\V1\PasswordResetController;
use App\Http\Controllers\Api\V1\PlaceController;
use App\Http\Controllers\Api\V1\SearchController;
use App\Http\Controllers\Api\V1\SearchSuggestionController;
use App\Models\Place;
use Illuminate\Support\Facades\Route;

// المسار الأساسي /api/v1 (06 القسم 1). أي تغيير في الـ API يتكتب في Scribe الأول.
Route::prefix('v1')->group(function () {
    Route::get('config', [ConfigController::class, 'index']);
    Route::get('categories', [CategoryController::class, 'index']);
    Route::get('attributes', [AttributeController::class, 'index']);
    Route::get('areas', [AreaController::class, 'index']);
    Route::get('places', [PlaceController::class, 'index']);
    Route::get('places/nearby', [PlaceController::class, 'nearby']);
    Route::get('places/count', [PlaceController::class, 'count']);
    // {slug} بعد المسارات الثابتة (nearby وcount وmap)، والـ where بيحمي لو اتضاف مسار ثابت بعده بالغلط:
    // الـ slug ما يطابقش كلمة محجوزة (Place::RESERVED_SLUGS)، فـ /places/count ما يتفسّرش على إنه مكان.
    Route::get('places/{slug}', [PlaceController::class, 'show'])
        ->where('slug', '(?!(?:'.implode('|', Place::RESERVED_SLUGS).')$)[^/]+');

    // البحث (F4): نفس كارت وفلاتر GET /places + q. بيتطبّع ويتوسّع بالمرادفات ويتصحّح إملائياً (PlaceSearch).
    Route::get('search', [SearchController::class, 'index']);
    // اقتراحات الكتابة (typeahead): استعلام صغير بيتبعت مع كل وقفة، فله limiter لوحده وأوسع (مش بيتحسب على `api`).
    Route::get('search/suggestions', [SearchSuggestionController::class, 'index'])
        ->middleware('throttle:search-suggestions')
        ->name('search.suggestions');

    Route::prefix('auth')->group(function () {
        // 5 محاولات في الدقيقة لكل IP + إيميل (SEC-01)
        Route::post('register', [AuthController::class, 'register'])->middleware('throttle:auth');
        Route::post('login', [AuthController::class, 'login'])->middleware('throttle:auth');

        // نسيت الباسورد: الإيميل ← الكود ← reset_token ← باسورد جديد. limiter لوحده (5 في الدقيقة لكل IP + إيميل،
        // ولكل خطوة ميزانيتها) عشان ما ياكلش من محاولات الدخول.
        Route::prefix('password')->middleware('throttle:password')->group(function () {
            Route::post('forgot', [PasswordResetController::class, 'forgot']);
            Route::post('verify', [PasswordResetController::class, 'verify']);
            Route::post('reset', [PasswordResetController::class, 'reset']);
        });

        Route::middleware('auth:sanctum')->group(function () {
            Route::post('logout', [AuthController::class, 'logout']);
            Route::get('me', [AuthController::class, 'me']);

            // تأكيد الإيميل: الكود بيتبعت لإيميل الحساب، و«ابعت تاني» بعد 60 ثانية
            Route::post('email/resend', [EmailVerificationController::class, 'resend'])->middleware('throttle:email-code');
            Route::post('email/verify', [EmailVerificationController::class, 'verify'])->middleware('throttle:email-code');
        });
    });
});
