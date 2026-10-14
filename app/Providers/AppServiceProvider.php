<?php

namespace App\Providers;

use App\Models\Area;
use App\Models\Attribute;
use App\Models\Category;
use App\Models\City;
use App\Models\Place;
use App\Policies\CatalogPolicy;
use App\Policies\PlacePolicy;
use App\Support\Search\CatalogSearchRefresh;
use App\Support\Search\DatabasePlaceSearch;
use App\Support\Search\PlaceSearch;
use App\Support\Search\SynonymDictionary;
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
        // البحث (F4): الـ API بيتكلّم مع الـ interface بس. الـ driver الحالي على الداتابيز، ولو الاستضافة
        // اتغيّرت لـ VPS يتبدّل هنا بـ Meilisearch من غير تغيير في الـ endpoint.
        $this->app->singleton(SynonymDictionary::class, fn () => SynonymDictionary::shared());
        $this->app->bind(PlaceSearch::class, DatabasePlaceSearch::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // SEC-01 / 06 القسم 1: الحد العام على كل /api/v1، زائر 60 طلب في الدقيقة ومستخدم مسجّل 120
        // (الأرقام في config('wain.rate_limit')). المسجّل بالـ user id من الـ Bearer token. الزائر بالجهاز
        // (X-Device-Id) مع الـ IP: شبكات الموبايل بتحط ناس كتير ورا IP واحد فالـ IP لوحده كان هيحجب ناس
        // ملهمش دعوة؛ ومن غير الهيدر بيرجع للـ IP. الـ limiters اللي تحت ده بتتطبق فوقه على نفس الطلب.
        RateLimiter::for('api', function (Request $request) {
            $user = $request->user('sanctum');

            if ($user !== null) {
                return Limit::perMinute((int) config('wain.rate_limit.user'))
                    ->by('user|'.$user->getAuthIdentifier());
            }

            $device = substr(trim((string) $request->header('X-Device-Id')), 0, 64);

            return Limit::perMinute((int) config('wain.rate_limit.guest'))
                ->by('guest|'.$request->ip().'|'.$device);
        });

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

        // اسم تصنيف أو خاصية أو منطقة اتغيّر: `search_text` الأماكن اللي تحته بيتحدّث في نفس الحفظ (F4).
        foreach ([Category::class, Attribute::class, Area::class] as $model) {
            $model::saved(CatalogSearchRefresh::handle(...));
        }

        // التصنيفات والخصائص والمدن والمناطق ليها نفس الصلاحيات: Admin يعدّل والـ Moderator عرض فقط.
        foreach ([Category::class, Attribute::class, City::class, Area::class] as $model) {
            Gate::policy($model, CatalogPolicy::class);
        }

        Gate::policy(Place::class, PlacePolicy::class);
    }
}
