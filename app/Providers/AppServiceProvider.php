<?php

namespace App\Providers;

use App\Models\Area;
use App\Models\Attribute;
use App\Models\Category;
use App\Models\City;
use App\Models\Place;
use App\Policies\CatalogPolicy;
use App\Policies\PlacePolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // SEC-01: الدخول والتسجيل 5 محاولات في الدقيقة لكل IP + إيميل. الزيادة بترجع 429 TOO_MANY_REQUESTS
        // ومعاها هيدر Retry-After (ApiErrorRenderer).
        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(5)
            ->by($request->ip().'|'.strtolower((string) $request->input('email'))));

        // نسيت الباسورد: 5 محاولات في الدقيقة لكل IP + إيميل (SEC-01)، **لكل خطوة لوحدها** (forgot وverify وreset)،
        // عشان المستخدم اللي بيعدّي الخطوات التلاتة بسرعة ومعاه غلطة أو اتنين ما يتحسبش على نفسه. وهو limiter
        // لوحده عشان ما ياكلش من محاولات الدخول. الكود نفسه ليه 5 محاولات و«ابعت تاني» بعد 60 ثانية.
        RateLimiter::for('password', fn (Request $request) => Limit::perMinute(5)
            ->by('password|'.$request->path().'|'.$request->ip().'|'.strtolower(trim((string) $request->input('email')))));

        // تأكيد الإيميل (resend وverify) للمستخدم المسجّل: 5 في الدقيقة لكل مستخدم ولكل خطوة، من غير ما
        // يتشارك مع الدخول.
        RateLimiter::for('email-code', fn (Request $request) => Limit::perMinute(5)
            ->by('email-code|'.$request->path().'|'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        // التصنيفات والخصائص والمدن والمناطق ليها نفس الصلاحيات: Admin يعدّل والـ Moderator عرض فقط.
        foreach ([Category::class, Attribute::class, City::class, Area::class] as $model) {
            Gate::policy($model, CatalogPolicy::class);
        }

        Gate::policy(Place::class, PlacePolicy::class);
    }
}
