<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * الإعدادات المبدئية (06 القسم 2 `settings` والقسم 6). كلها قيم مؤقتة بتتعدّل بعد كده من اللوحة:
 *
 * - `ramadan_*`: تواريخ رمضان 2027 التقريبية (مثال من 06 `GET /config`)، تتأكد من دار الإفتاء قبل رمضان.
 * - `terms_url` و`privacy_url` و`support_email`: على دومين `wain.app` المثال لحد ما يتأكد ويتحجز.
 * - `support_whatsapp` فاضي لحد ما يبقى فيه رقم دعم.
 *
 * insertOrIgnore: إعادة التشغيل ما بتمسحش قيمة اتعدّلت. بيشتغل على production.
 */
class SettingSeeder extends Seeder
{
    /** @var array<string, string> */
    public const DEFAULTS = [
        'price_budget_max' => '250',
        'price_mid_max' => '600',
        'ramadan_starts_at' => '2027-02-08',
        'ramadan_ends_at' => '2027-03-09',
        'min_app_version_ios' => '1.0.0',
        'min_app_version_android' => '1.0.0',
        'support_whatsapp' => '',
        'support_email' => 'support@wain.app',
        'terms_url' => 'https://wain.app/terms',
        'privacy_url' => 'https://wain.app/privacy',
        'terms_version' => '2026-10',
    ];

    public function run(): void
    {
        $now = now();

        DB::table('settings')->insertOrIgnore(
            collect(self::DEFAULTS)
                ->map(fn (string $value, string $key) => ['key' => $key, 'value' => $value, 'updated_at' => $now])
                ->values()
                ->all(),
        );
    }
}
