<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\PriceRange;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * @group Config
 */
class ConfigController extends Controller
{
    /** مفتاح الـ cache (file)، 5 دقايق (06 القسم 5). تعديل `settings` من اللوحة لازم يمسحه. */
    public const CACHE_KEY = 'config';

    /**
     * App config
     *
     * إعدادات التطبيق من جدول `settings`: الحد الأدنى لنسخة التطبيق، تواريخ رمضان، روابط الشروط والخصوصية،
     * بيانات الدعم وحدود فئات السعر. عام ومن غير token، والتطبيق بيطلبه عند الفتح وبيخزّنه.
     *
     * القيمة اللي مش متحددة بترجع `null` (مثلاً رقم واتساب الدعم لحد ما يبقى فيه رقم). بيتخزن في cache 5 دقايق.
     *
     * @response 200 {"data": {"min_app_version": {"ios": "1.0.0", "android": "1.0.0"}, "ramadan": {"starts_at": "2027-02-08", "ends_at": "2027-03-09"}, "terms_version": "2026-10", "terms_url": "https://wain.app/terms", "privacy_url": "https://wain.app/privacy", "support": {"whatsapp": null, "email": "support@wain.app"}, "price_ranges": {"budget_max": 250, "mid_max": 600}}}
     */
    public function index(): JsonResponse
    {
        $settings = Cache::remember(
            self::CACHE_KEY,
            now()->addMinutes(5),
            fn () => DB::table('settings')->pluck('value', 'key')->all(),
        );

        // القيمة الفاضية («مش متحددة بعد») بترجع null مش نص فاضي
        $value = fn (string $key, ?string $default = null) => filled($settings[$key] ?? null) ? $settings[$key] : $default;

        return response()->json([
            'data' => [
                'min_app_version' => [
                    'ios' => $value('min_app_version_ios', '1.0.0'),
                    'android' => $value('min_app_version_android', '1.0.0'),
                ],
                'ramadan' => [
                    'starts_at' => $value('ramadan_starts_at'),
                    'ends_at' => $value('ramadan_ends_at'),
                ],
                'terms_version' => $value('terms_version'),
                'terms_url' => $value('terms_url'),
                'privacy_url' => $value('privacy_url'),
                'support' => [
                    'whatsapp' => $value('support_whatsapp'),
                    'email' => $value('support_email'),
                ],
                'price_ranges' => [
                    'budget_max' => (int) $value('price_budget_max', (string) PriceRange::BUDGET_MAX),
                    'mid_max' => (int) $value('price_mid_max', (string) PriceRange::MID_MAX),
                ],
            ],
        ]);
    }
}
