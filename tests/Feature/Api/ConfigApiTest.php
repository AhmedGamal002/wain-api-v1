<?php

namespace Tests\Feature\Api;

use App\Http\Controllers\Api\V1\ConfigController;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * GET /config (06 القسم 1): إعدادات التطبيق من جدول settings، عام ومن غير token.
 */
class ConfigApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_the_documented_shape_from_the_seeded_settings(): void
    {
        $this->seed(SettingSeeder::class);

        $this->getJson('/api/v1/config')
            ->assertOk()
            ->assertExactJson(['data' => [
                'min_app_version' => ['ios' => '1.0.0', 'android' => '1.0.0'],
                'ramadan' => ['starts_at' => '2027-02-08', 'ends_at' => '2027-03-09'],
                'terms_version' => '2026-10',
                'terms_url' => 'https://wain.app/terms',
                'privacy_url' => 'https://wain.app/privacy',
                // رقم الدعم لسه فاضي: null مش نص فاضي
                'support' => ['whatsapp' => null, 'email' => 'support@wain.app'],
                'price_ranges' => ['budget_max' => 250, 'mid_max' => 600],
            ]]);
    }

    public function test_values_changed_in_settings_are_returned(): void
    {
        $this->seed(SettingSeeder::class);
        DB::table('settings')->where('key', 'price_mid_max')->update(['value' => '700']);
        DB::table('settings')->where('key', 'support_whatsapp')->update(['value' => '+201000000000']);
        DB::table('settings')->where('key', 'min_app_version_android')->update(['value' => '1.2.0']);

        $this->getJson('/api/v1/config')
            ->assertOk()
            ->assertJsonPath('data.price_ranges.mid_max', 700)
            ->assertJsonPath('data.support.whatsapp', '+201000000000')
            ->assertJsonPath('data.min_app_version.android', '1.2.0')
            ->assertJsonPath('data.min_app_version.ios', '1.0.0');
    }

    public function test_empty_settings_fall_back_to_safe_defaults(): void
    {
        $this->getJson('/api/v1/config')
            ->assertOk()
            ->assertExactJson(['data' => [
                'min_app_version' => ['ios' => '1.0.0', 'android' => '1.0.0'],
                'ramadan' => ['starts_at' => null, 'ends_at' => null],
                'terms_version' => null,
                'terms_url' => null,
                'privacy_url' => null,
                'support' => ['whatsapp' => null, 'email' => null],
                'price_ranges' => ['budget_max' => 250, 'mid_max' => 600],
            ]]);
    }

    public function test_response_is_cached_until_the_key_is_forgotten(): void
    {
        $this->seed(SettingSeeder::class);

        $this->getJson('/api/v1/config')->assertJsonPath('data.price_ranges.budget_max', 250);
        $this->assertTrue(Cache::has(ConfigController::CACHE_KEY));

        DB::table('settings')->where('key', 'price_budget_max')->update(['value' => '300']);
        $this->getJson('/api/v1/config')->assertJsonPath('data.price_ranges.budget_max', 250);

        Cache::forget(ConfigController::CACHE_KEY);
        $this->getJson('/api/v1/config')->assertJsonPath('data.price_ranges.budget_max', 300);
    }

    public function test_it_is_public(): void
    {
        $this->getJson('/api/v1/config')->assertOk();
    }
}
