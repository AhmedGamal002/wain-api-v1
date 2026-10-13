<?php

namespace Tests\Feature\Api;

use App\Models\Area;
use App\Models\City;
use Database\Seeders\DemoPlaceSeeder;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * GET /areas (A4): المناطق المتاحة بس (المنطقة والمدينة فعّالتين) ومعاها عدد الأماكن المنشورة.
 */
class AreaApiTest extends TestCase
{
    use RefreshDatabase;

    private const AR = ['Accept-Language' => 'ar'];

    private const EN = ['Accept-Language' => 'en'];

    private function city(string $slug, int $order, array $extra = []): City
    {
        return City::query()->create($extra + [
            'slug' => $slug, 'name_ar' => 'مدينة '.$slug, 'name_en' => 'City '.$slug, 'sort_order' => $order,
        ]);
    }

    private function area(City $city, string $slug, int $order, array $extra = []): Area
    {
        return Area::query()->create($extra + [
            'city_id' => $city->id,
            'slug' => $slug,
            'name_ar' => 'منطقة '.$slug,
            'name_en' => 'Area '.$slug,
            'center_lat' => 30.03,
            'center_lng' => 31.47,
            'sort_order' => $order,
            'is_active' => true,
        ]);
    }

    public function test_returns_only_active_areas_of_active_cities_in_order(): void
    {
        $cairo = $this->city('cairo', 1);
        $giza = $this->city('giza', 2);
        $hiddenCity = $this->city('alex', 3, ['is_active' => false]);

        $this->area($giza, 'dokki', 1);
        $this->area($cairo, 'maadi', 2);
        $this->area($cairo, 'zamalek', 1);
        $this->area($cairo, 'hidden-area', 3, ['is_active' => false]);
        $this->area($hiddenCity, 'stanley', 1);

        $json = $this->getJson('/api/v1/areas', self::AR)->assertOk()->json('data');

        // المدينة الأول ثم ترتيب المنطقة جواها
        $this->assertSame(['zamalek', 'maadi', 'dokki'], array_column($json, 'slug'));
    }

    public function test_area_shape(): void
    {
        $cairo = $this->city('cairo', 1);
        $this->area($cairo, 'new-cairo', 1, ['center_lat' => 30.0300000, 'center_lng' => 31.4700000]);

        $this->getJson('/api/v1/areas', self::AR)
            ->assertOk()
            ->assertExactJson(['data' => [[
                'slug' => 'new-cairo',
                'name' => 'منطقة new-cairo',
                'city' => ['slug' => 'cairo', 'name' => 'مدينة cairo'],
                'center' => ['lat' => 30.03, 'lng' => 31.47],
                'places_count' => 0,
            ]]]);
    }

    public function test_places_count_is_the_published_places_only(): void
    {
        $this->seed(DemoPlaceSeeder::class);

        $newCairo = Area::query()->where('slug', 'new-cairo')->firstOrFail();
        $published = DB::table('places')->where('area_id', $newCairo->id)->where('status', 'active')->whereNull('deleted_at')->count();
        $all = DB::table('places')->where('area_id', $newCairo->id)->count();
        $this->assertGreaterThan($published, $all, 'the demo data has hidden, draft and pending places on purpose');

        $this->getJson('/api/v1/areas', self::AR)
            ->assertOk()
            ->assertJsonPath('data.0.slug', 'new-cairo')
            ->assertJsonPath('data.0.places_count', $published);
    }

    public function test_names_follow_accept_language_and_fall_back_to_arabic(): void
    {
        $cairo = $this->city('cairo', 1, ['name_ar' => 'القاهرة', 'name_en' => 'Cairo']);
        $this->area($cairo, 'zamalek', 1, ['name_ar' => 'الزمالك', 'name_en' => 'Zamalek']);
        $this->area($cairo, 'maadi', 2, ['name_ar' => 'المعادي', 'name_en' => '']);

        $ar = $this->getJson('/api/v1/areas', self::AR)->json('data');
        $en = $this->getJson('/api/v1/areas', self::EN)->json('data');

        $this->assertSame(['الزمالك', 'المعادي'], array_column($ar, 'name'));
        $this->assertSame('القاهرة', $ar[0]['city']['name']);
        $this->assertSame(['Zamalek', 'المعادي'], array_column($en, 'name'));
        $this->assertSame('Cairo', $en[0]['city']['name']);
    }

    public function test_cache_is_cleared_when_an_area_or_a_city_changes(): void
    {
        $cairo = $this->city('cairo', 1);
        $area = $this->area($cairo, 'zamalek', 1);

        $this->getJson('/api/v1/areas')->assertJsonCount(1, 'data');
        $this->assertTrue(Cache::has(Area::CACHE_KEY));

        $area->update(['is_active' => false]);
        $this->assertFalse(Cache::has(Area::CACHE_KEY));
        $this->getJson('/api/v1/areas')->assertJsonCount(0, 'data');

        $area->update(['is_active' => true]);
        $this->getJson('/api/v1/areas')->assertJsonCount(1, 'data');

        // إخفاء المدينة بيخفي مناطقها
        $cairo->update(['is_active' => false]);
        $this->assertFalse(Cache::has(Area::CACHE_KEY));
        $this->getJson('/api/v1/areas')->assertJsonCount(0, 'data');
    }

    public function test_second_request_served_from_a_real_file_cache_works(): void
    {
        $path = storage_path('framework/testing/cache-'.uniqid());
        config(['cache.default' => 'file', 'cache.stores.file.path' => $path]);
        Cache::purge();

        try {
            $cairo = $this->city('cairo', 1, ['name_ar' => 'القاهرة', 'name_en' => 'Cairo']);
            $this->area($cairo, 'zamalek', 1, ['name_ar' => 'الزمالك', 'name_en' => 'Zamalek']);

            $this->getJson('/api/v1/areas', self::AR)->assertOk()->assertJsonPath('data.0.name', 'الزمالك');
            $this->getJson('/api/v1/areas', self::EN)->assertOk()->assertJsonPath('data.0.name', 'Zamalek');
            $this->assertTrue(Cache::has(Area::CACHE_KEY));
        } finally {
            Cache::flush();
            Cache::purge();
            (new Filesystem)->deleteDirectory($path);
        }
    }
}
