<?php

namespace Tests\Feature;

use App\Support\OpenNow;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SettingSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_every_key_documented_in_06(): void
    {
        $this->seed(SettingSeeder::class);

        $keys = DB::table('settings')->pluck('value', 'key');

        $this->assertEqualsCanonicalizing([
            'price_budget_max', 'price_mid_max', 'ramadan_starts_at', 'ramadan_ends_at',
            'min_app_version_ios', 'min_app_version_android', 'support_whatsapp', 'support_email',
            'terms_url', 'privacy_url', 'terms_version',
        ], $keys->keys()->all());

        $this->assertSame('250', $keys['price_budget_max']);
        $this->assertSame('600', $keys['price_mid_max']);
    }

    public function test_rerun_does_not_overwrite_a_value_changed_from_the_panel(): void
    {
        $this->seed(SettingSeeder::class);
        DB::table('settings')->where('key', 'price_mid_max')->update(['value' => '700']);

        $this->seed(SettingSeeder::class);

        $this->assertSame('700', DB::table('settings')->where('key', 'price_mid_max')->value('value'));
    }

    public function test_seeded_ramadan_dates_are_valid_and_ordered(): void
    {
        $this->seed(SettingSeeder::class);

        $starts = DB::table('settings')->where('key', 'ramadan_starts_at')->value('value');
        $ends = DB::table('settings')->where('key', 'ramadan_ends_at')->value('value');

        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $starts);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $ends);
        $this->assertLessThan($ends, $starts);

        // الـ OpenNow بيقراهم: مش رمضان النهارده (أكتوبر 2026)، ورمضان في منتصف الفترة
        $this->assertFalse(OpenNow::at(now('Africa/Cairo')->setDate(2026, 10, 13))->ramadan);
        $this->assertTrue(OpenNow::at(now('Africa/Cairo')->setDate(2027, 2, 20))->ramadan);
    }
}
