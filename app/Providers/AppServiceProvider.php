<?php

namespace App\Providers;

use App\Models\Area;
use App\Models\Attribute;
use App\Models\Category;
use App\Models\City;
use App\Policies\CatalogPolicy;
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

        // التصنيفات والخصائص والمدن والمناطق ليها نفس الصلاحيات: Admin يعدّل والـ Moderator عرض فقط.
        foreach ([Category::class, Attribute::class, City::class, Area::class] as $model) {
            Gate::policy($model, CatalogPolicy::class);
        }
    }
}
