<?php

namespace App\Providers;

use App\Models\Area;
use App\Models\Attribute;
use App\Models\Category;
use App\Models\City;
use App\Models\Place;
use App\Models\Review;
use App\Models\User;
use App\Policies\CatalogPolicy;
use App\Policies\PlacePolicy;
use App\Policies\ReviewPolicy;
use App\Policies\UserPolicy;
use App\Support\Search\CatalogSearchRefresh;
use App\Support\Search\DatabasePlaceSearch;
use App\Support\Search\PlaceSearch;
use App\Support\Search\SynonymDictionary;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\HttpFoundation\Response;

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
        //
        // الهيدر ده بيجي من العميل، فتغييره بيفتح ميزانية جديدة: عشان كده فوق ميزانية الجهاز سقف تاني على الـ IP
        // لوحده (`guest_ip`، أوسع بكتير للـ CGNAT). الطلب المحجوب ما بيتحسبش على أي ميزانية (Laravel بيفحص الكل
        // قبل ما يعدّ)، وسقف الـ IP الأول في المصفوفة عشان هيدرز `X-RateLimit-*` تفضل بتاعة ميزانية الجهاز.
        RateLimiter::for('api', function (Request $request) {
            // اقتراحات الكتابة والأحداث ليها limiters لوحدها (تحت): ما بتتحسبش هنا، وما بتاكلش من ميزانية باقي التطبيق
            if ($request->routeIs('search.suggestions', 'places.events')) {
                return Limit::none();
            }

            $user = $request->user('sanctum');

            if ($user !== null) {
                return Limit::perMinute((int) config('wain.rate_limit.user'))
                    ->by('user|'.$user->getAuthIdentifier());
            }

            return [
                Limit::perMinute((int) config('wain.rate_limit.guest_ip'))
                    ->by('guest-ip|'.$request->ip()),
                Limit::perMinute((int) config('wain.rate_limit.guest'))
                    ->by('guest|'.$request->ip().'|'.$this->deviceKey($request)),
            ];
        });

        // اقتراحات الكتابة (F4): طلب لكل وقفة في الكتابة، فلو اتحسبت على الحد العام (60) كلمتين بحث كانوا هيحجبوا
        // الرئيسية وصفحة المكان لدقيقة. حدّها أوسع (120 زائر و240 مستخدم) ومنفصل، وبنفس هوية الحد العام.
        // استعلام البحث هو الأتقل على الداتابيز، فالزائر عليه سقف IP برضه (من غيره تغيير الهيدر بيفتح ميزانية جديدة).
        RateLimiter::for('search-suggestions', function (Request $request) {
            $user = $request->user('sanctum');

            if ($user !== null) {
                return Limit::perMinute((int) config('wain.rate_limit.suggestions_user'))
                    ->by('suggestions|user|'.$user->getAuthIdentifier());
            }

            return [
                Limit::perMinute((int) config('wain.rate_limit.suggestions_ip'))
                    ->by('suggestions|ip|'.$request->ip()),
                Limit::perMinute((int) config('wain.rate_limit.suggestions_guest'))
                    ->by('suggestions|guest|'.$request->ip().'|'.$this->deviceKey($request)),
            ];
        });

        // أحداث الموبايل (ANL-02): 60 في الدقيقة (03 القسم 5.5) للمستخدم المسجّل أو للجهاز + الـ IP. فتح صفحة وكل
        // ضغطة زرار حدث، فالحد أوسع بكتير من الاستخدام الطبيعي، وبيحمي الجدول من تطبيق بيلفّ أو سكريبت.
        // الـ UUID أي قيمة، فمن غير سقف الـ IP (`events_ip`، للزائر والمسجّل) تغييره كل طلب بيزوّر الإحصائيات.
        RateLimiter::for('events', function (Request $request) {
            $user = $request->user('sanctum');

            $perIdentity = $user !== null
                ? Limit::perMinute((int) config('wain.rate_limit.events'))->by('events|user|'.$user->getAuthIdentifier())
                : Limit::perMinute((int) config('wain.rate_limit.events'))
                    ->by('events|guest|'.$request->ip().'|'.$this->deviceKey($request));

            return [
                Limit::perMinute((int) config('wain.rate_limit.events_ip'))->by('events|ip|'.$request->ip()),
                $perIdentity,
            ];
        });

        // SEC-01: الدخول والتسجيل 5 محاولات في الدقيقة لكل IP + إيميل. الزيادة بترجع 429 TOO_MANY_REQUESTS
        // ومعاها هيدر Retry-After (ApiErrorRenderer). الحد ده لوحده بيعدّي منه إيميلات كتير من IP واحد (تخمين
        // باسوردات على ألف حساب، أو ألف حساب مزيّف)، فجنبه: سقف على الـ IP في الدقيقة وفي الساعة، وسقف على الإيميل
        // من أي IP (هجوم موزّع على حساب واحد). الأرقام في config('wain.rate_limit.auth_*').
        //
        // **الدخول بيعدّ المحاولات الفاشلة بس** (`Limit::after`، بعد الرد): ناس كتير بيدخلوا صح من نفس الواي فاي أو
        // نفس شبكة الموبايل ما بياكلوش من أي ميزانية. الفحص (هل الحد اتعدّى؟) لسه قبل الطلب. التسجيل بيتعدّ كله:
        // هو اللي بيعمل حسابات وبيكلّف bcrypt، سواء نجح أو فشل.
        RateLimiter::for('auth', function (Request $request) {
            $ip = (string) $request->ip();
            $email = $this->emailKey($request);

            $limits = array_values(array_filter([
                $email !== '' ? Limit::perMinute(5)->by($ip.'|'.$email) : null,
                Limit::perMinute((int) config('wain.rate_limit.auth_ip_minute'))->by('auth-ip|'.$ip),
                Limit::perHour((int) config('wain.rate_limit.auth_ip_hour'))->by('auth-ip-hour|'.$ip),
                $email !== '' ? Limit::perMinute((int) config('wain.rate_limit.auth_email'))->by('auth-email|'.$email) : null,
            ]));

            if ($request->is('api/v1/auth/login')) {
                foreach ($limits as $limit) {
                    $limit->after(fn (Response $response): bool => $response->getStatusCode() >= 400);
                }
            }

            return $limits;
        });

        // نسيت الباسورد: 5 محاولات في الدقيقة لكل IP + إيميل (SEC-01)، **لكل خطوة لوحدها** (forgot وverify وreset)،
        // عشان المستخدم اللي بيعدّي الخطوات التلاتة بسرعة ومعاه غلطة أو اتنين ما يتحسبش على نفسه. وهو limiter
        // لوحده عشان ما ياكلش من محاولات الدخول. الكود نفسه ليه 5 محاولات و«ابعت تاني» بعد 60 ثانية.
        // وزي `auth`: سقف على الـ IP وعلى الإيميل من أي IP، لكل خطوة (الكود 6 أرقام، فتخمينه على إيميلات كتير
        // من IP واحد هو اللي بيتسدّ).
        RateLimiter::for('password', function (Request $request) {
            $ip = (string) $request->ip();
            $email = $this->emailKey($request);
            $step = $request->path();

            return array_values(array_filter([
                Limit::perMinute(5)->by('password|'.$step.'|'.$ip.'|'.$email),
                Limit::perMinute((int) config('wain.rate_limit.auth_ip_minute'))->by('password-ip|'.$step.'|'.$ip),
                Limit::perHour((int) config('wain.rate_limit.auth_ip_hour'))->by('password-ip-hour|'.$step.'|'.$ip),
                $email !== '' ? Limit::perMinute((int) config('wain.rate_limit.auth_email'))->by('password-email|'.$step.'|'.$email) : null,
            ]));
        });

        // كتابة التقييمات ورفع صورها (F8): لكل مستخدم مسجّل في الدقيقة، وبرضه بره الحد العام (`api`).
        RateLimiter::for('reviews', fn (Request $request) => Limit::perMinute((int) config('wain.rate_limit.reviews'))
            ->by('reviews|'.($request->user('sanctum')?->getAuthIdentifier() ?? $request->ip())));
        RateLimiter::for('uploads', fn (Request $request) => Limit::perMinute((int) config('wain.rate_limit.uploads'))
            ->by('uploads|'.($request->user('sanctum')?->getAuthIdentifier() ?? $request->ip())));

        // رابط دعوة الفريق (A7): صفحة الفورم وزرار القبول. الـ token عشوائي 64 حرف فالتخمين مش بيحصل، والحد لمنع
        // الطرق على الرابط وعلى فورم الباسورد.
        RateLimiter::for('team-invitation', fn (Request $request) => Limit::perMinute(10)->by('team-invitation|'.$request->ip()));

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
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Review::class, ReviewPolicy::class);
    }

    /**
     * هوية الجهاز في مفاتيح الـ limiters: `X-Device-Id` مقصوص لـ 64 حرف (من العميل، فمش موثوق لوحده).
     */
    private function deviceKey(Request $request): string
    {
        return substr(trim((string) $request->header('X-Device-Id')), 0, 64);
    }

    /**
     * الإيميل في مفاتيح الـ limiters: مصغّر ومقصوص. فاضي لو مش نص أو مش موجود، فالحدود اللي بالإيميل بتتخطى
     * (مفيش «bucket» مشترك لكل الطلبات اللي من غير إيميل).
     */
    private function emailKey(Request $request): string
    {
        $email = $request->input('email');

        return is_string($email) ? strtolower(substr(trim($email), 0, 255)) : '';
    }
}
