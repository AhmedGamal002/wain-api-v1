<?php

use App\Http\Controllers\Api\V1\AreaController;
use App\Http\Controllers\Api\V1\AttributeController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\ConfigController;
use App\Http\Controllers\Api\V1\EmailVerificationController;
use App\Http\Controllers\Api\V1\HomeController;
use App\Http\Controllers\Api\V1\PasswordResetController;
use App\Http\Controllers\Api\V1\PlaceController;
use App\Http\Controllers\Api\V1\PlaceEventController;
use App\Http\Controllers\Api\V1\PlaceReviewController;
use App\Http\Controllers\Api\V1\ReviewController;
use App\Http\Controllers\Api\V1\SearchController;
use App\Http\Controllers\Api\V1\SearchSuggestionController;
use App\Http\Controllers\Api\V1\UploadController;
use App\Models\Place;
use Illuminate\Support\Facades\Route;

// المسار الأساسي /api/v1 (06 القسم 1). أي تغيير في الـ API يتكتب في Scribe الأول.
Route::prefix('v1')->group(function () {
    Route::get('config', [ConfigController::class, 'index']);
    Route::get('categories', [CategoryController::class, 'index']);
    Route::get('attributes', [AttributeController::class, 'index']);
    Route::get('areas', [AreaController::class, 'index']);
    // الرئيسية في request واحد (F3): المجموعات (فاضية لحد P6) و«قريب منك»
    Route::get('home', [HomeController::class, 'index']);
    Route::get('places', [PlaceController::class, 'index']);
    Route::get('places/nearby', [PlaceController::class, 'nearby']);
    Route::get('places/count', [PlaceController::class, 'count']);
    Route::get('places/map', [PlaceController::class, 'map']);
    // {slug} بعد المسارات الثابتة (nearby وcount وmap)، والـ where بيحمي لو اتضاف مسار ثابت بعده بالغلط:
    // الـ slug ما يطابقش كلمة محجوزة (Place::RESERVED_SLUGS)، فـ /places/count ما يتفسّرش على إنه مكان.
    Route::get('places/{slug}', [PlaceController::class, 'show'])
        ->where('slug', '(?!(?:'.implode('|', Place::RESERVED_SLUGS).')$)[^/]+');

    // تقييمات المكان (F8): القراءة للكل (والمستخدم الداخل بيشوف تقييمه هو بأي حالة)
    Route::get('places/{slug}/reviews', [PlaceReviewController::class, 'index']);

    // أحداث الموبايل (ANL-02، 03 القسم 5.5): 204 فوراً والكتابة على الـ queue. limiter لوحده (60 في الدقيقة) وبره
    // الحد العام (AppServiceProvider) عشان فتح صفحة وضغطات أزرارها ما ياكلوش من ميزانية التصفّح.
    Route::post('places/{slug}/events', [PlaceEventController::class, 'store'])
        ->middleware('throttle:events')
        ->name('places.events');

    // البحث (F4): نفس كارت وفلاتر GET /places + q. بيتطبّع ويتوسّع بالمرادفات ويتصحّح إملائياً (PlaceSearch).
    Route::get('search', [SearchController::class, 'index']);
    // اقتراحات الكتابة (typeahead): استعلام صغير بيتبعت مع كل وقفة، فله limiter لوحده وأوسع (مش بيتحسب على `api`).
    Route::get('search/suggestions', [SearchSuggestionController::class, 'index'])
        ->middleware('throttle:search-suggestions')
        ->name('search.suggestions');

    // التقييمات والرفع (F8): حساب شغّال. الكتابة والرفع محتاجين إيميل متأكَّد (403 EMAIL_NOT_VERIFIED)، وتعديل
    // التقييم وحذفه لصاحبه بس (تقييم حد تاني 404). limiter لكل مستخدم بره الحد العام للكتابة والرفع.
    Route::middleware(['auth:sanctum', 'active'])->group(function () {
        Route::post('uploads/photos', [UploadController::class, 'photo'])
            ->middleware(['verified-email', 'throttle:uploads']);
        Route::post('places/{slug}/reviews', [PlaceReviewController::class, 'store'])
            ->middleware(['verified-email', 'throttle:reviews']);
        Route::put('reviews/{id}', [ReviewController::class, 'update'])->whereNumber('id');
        Route::delete('reviews/{id}', [ReviewController::class, 'destroy'])->whereNumber('id');
    });

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

        // `active`: موقوف = 403 ACCOUNT_SUSPENDED (A7)، بعد auth:sanctum
        Route::middleware(['auth:sanctum', 'active'])->group(function () {
            Route::post('logout', [AuthController::class, 'logout']);
            Route::get('me', [AuthController::class, 'me']);

            // تأكيد الإيميل: الكود بيتبعت لإيميل الحساب، و«ابعت تاني» بعد 60 ثانية
            Route::post('email/resend', [EmailVerificationController::class, 'resend'])->middleware('throttle:email-code');
            Route::post('email/verify', [EmailVerificationController::class, 'verify'])->middleware('throttle:email-code');
        });
    });
});
