<?php

namespace Tests\Performance;

use App\Support\OpeningHours;
use App\Support\PlaceLocation;
use App\Support\Search\PlaceSearchText;
use Database\Seeders\AttributeSeeder;
use Database\Seeders\CategorySeeder;
use Database\Seeders\CityAreaSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * معيار أداء القوائم (03 القسم 8، الـ API): «القوائم p95 أقل من 300ms مع 5000 مكان تجريبي» و«`nearby`
 * بيستخدم الـ spatial index (EXPLAIN)».
 *
 * **مش جزء من الـ suite العادي** (مش في `phpunit.xml`): بيبني 5000 مكان وبياخد دقايق. بيتشغّل بالمسار:
 *
 *     php -d xdebug.mode=off vendor/bin/phpunit tests/Performance
 *
 * - `DatabaseMigrations` مش `RefreshDatabase`: القياس محتاج `ANALYZE TABLE`، وهو بيعمل commit ضمني جوه
 *   transaction فكان هيسيب الـ 5000 مكان في `wain_test`. هنا الجداول بتتمسح في الآخر.
 * - كل طلب بيتقاس بارد (`Cache::flush()` قبله): ده أسوأ حالة. اتحسبت الـ p95 على الطلبات دي، من جوه
 *   الـ kernel (من غير شبكة ولا bootstrap بتاع PHP-FPM).
 * - الأرقام بتتطبع على STDERR. القياس على Docker على جهاز تطوير، فهو أبطأ من الاستضافة غالباً.
 */
class PlaceListingPerformanceTest extends TestCase
{
    use DatabaseMigrations;

    private const PLACES = 5000;

    private const RUNS = 20;

    private const WARMUP = 2;

    private const P95_BUDGET_MS = 300;

    /** مركز القاهرة الكبرى: الأماكن بتتوزّع حواليه عشان المستطيل الصغير ما يلقطش كلها. */
    private const CENTER = [30.05, 31.50];

    private const AR = ['Accept-Language' => 'ar'];

    public function test_listings_stay_under_the_budget_with_5000_places(): void
    {
        $this->seed([CategorySeeder::class, CityAreaSeeder::class, AttributeSeeder::class]);
        $this->seedPlaces();

        $this->assertSame(self::PLACES, DB::table('places')->count());

        $this->assertNearbyUsesTheSpatialIndex();

        $budgeted = [
            'القايمة الافتراضية' => '/api/v1/places?area=new-cairo',
            'تصنيف' => '/api/v1/places?area=new-cairo&categories[]=cafes',
            'الأحدث' => '/api/v1/places?area=new-cairo&sort=newest',
            'التقييم' => '/api/v1/places?area=new-cairo&sort=rating',
            'الأقرب' => '/api/v1/places?area=new-cairo&sort=nearest&lat=30.05&lng=31.50',
            'مفتوح دلوقتي' => '/api/v1/places?area=new-cairo&open_now=1',
            'خصائص وسعر' => '/api/v1/places?area=new-cairo&attributes[]=wifi&attributes[]=quiet&price_max=300',
            'صفحة 5' => '/api/v1/places?area=new-cairo&page=5',
            'العدد' => '/api/v1/places/count?area=new-cairo&categories[]=cafes&open_now=1',
            'الأماكن القريبة' => '/api/v1/places/nearby?lat=30.05&lng=31.50&radius=5',
            'الخريطة' => '/api/v1/places/map?bbox=31.40,29.98,31.60,30.12',
            'الرئيسية' => '/api/v1/home?area=new-cairo&lat=30.05&lng=31.50',
        ];

        // البحث في معيار F4 (أقل من 200ms) مش هنا: بيتقاس للمعلومية بس
        $informational = [
            'البحث' => '/api/v1/search?q=قهوة&area=new-cairo',
            'اقتراحات الكتابة' => '/api/v1/search/suggestions?q=قه',
        ];

        $over = [];
        $rows = [];

        foreach ($budgeted as $label => $url) {
            $stats = $this->measure($url);
            $rows[] = [$label, $stats];

            if ($stats['p95'] >= self::P95_BUDGET_MS) {
                $over[] = sprintf('%s p95=%.0fms', $label, $stats['p95']);
            }
        }

        foreach ($informational as $label => $url) {
            $rows[] = [$label.' (للمعلومية)', $this->measure($url)];
        }

        $this->report($rows);

        $this->assertSame([], $over, 'p95 عدّى '.self::P95_BUDGET_MS.'ms: '.implode('، ', $over));
    }

    /**
     * @return array{p50: float, p95: float, max: float}
     */
    private function measure(string $url): array
    {
        for ($i = 0; $i < self::WARMUP; $i++) {
            Cache::flush();
            $this->getJson($url, self::AR)->assertOk();
        }

        $times = [];
        for ($i = 0; $i < self::RUNS; $i++) {
            Cache::flush();

            $start = hrtime(true);
            $this->getJson($url, self::AR)->assertOk();
            $times[] = (hrtime(true) - $start) / 1_000_000;
        }

        sort($times);

        return [
            'p50' => $times[(int) floor(count($times) * 0.5)],
            'p95' => $times[(int) ceil(count($times) * 0.95) - 1],
            'max' => end($times),
        ];
    }

    /**
     * @param  list<array{0: string, 1: array{p50: float, p95: float, max: float}}>  $rows
     */
    private function report(array $rows): void
    {
        $out = sprintf("\n%d مكان، %d طلب بارد لكل endpoint (الميزانية p95 < %dms)\n", self::PLACES, self::RUNS, self::P95_BUDGET_MS);
        $out .= sprintf("%-28s %8s %8s %8s\n", 'endpoint', 'p50', 'p95', 'max');

        foreach ($rows as [$label, $s]) {
            $out .= sprintf("%-28s %7.0fms %7.0fms %7.0fms\n", $label, $s['p50'], $s['p95'], $s['max']);
        }

        fwrite(STDERR, $out);
    }

    /**
     * الاستعلام الفعلي بتاع `GET /places/nearby` (مش واحد مكتوب باليد): بنلقطه من الـ connection ونعمله EXPLAIN.
     */
    private function assertNearbyUsesTheSpatialIndex(): void
    {
        $captured = null;

        DB::listen(function ($query) use (&$captured) {
            if ($captured === null && str_contains($query->sql, 'MBRContains')) {
                $captured = [$query->sql, $query->bindings];
            }
        });

        $this->getJson('/api/v1/places/nearby?lat=30.05&lng=31.50&radius=5', self::AR)->assertOk();

        $this->assertNotNull($captured, 'nearby ما استخدمش MBRContains');

        $plan = DB::select('EXPLAIN '.$captured[0], $captured[1]);
        $keys = array_map(fn ($row) => (string) ($row->key ?? ''), $plan);

        fwrite(STDERR, "\nnearby EXPLAIN: ".json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");

        $this->assertNotEmpty(
            array_filter($keys, fn (string $key) => str_contains($key, 'location')),
            'nearby مش بيستخدم الـ spatial index. الـ keys: '.implode(', ', $keys),
        );
    }

    /**
     * 5000 مكان بالجملة (من غير الـ Observer): الحالات والباقات والتصنيفات والخصائص والمواعيد والتقييم موزّعة
     * زي الواقع، وبعدها `search_text` و`ANALYZE TABLE` عشان الـ optimizer يشوف الأحجام الحقيقية.
     */
    private function seedPlaces(): void
    {
        mt_srand(20261015);

        $categoryIds = DB::table('categories')->pluck('id')->all();
        $attributeIds = DB::table('attributes')->pluck('id')->all();
        $cityId = (int) DB::table('cities')->where('slug', 'cairo')->value('id');
        $areaId = (int) DB::table('areas')->where('slug', 'new-cairo')->value('id');

        $words = ['قهوة', 'كافيه', 'مطعم', 'بيتزا', 'برجر', 'حلويات', 'روف', 'حديقة', 'مشويات', 'كشري'];
        $suffixes = ['المحطة', 'الأصدقاء', 'النيل', 'الورد', 'الريف', 'السعادة', 'الهدوء', 'القمر', 'الجنينة', 'الشام'];
        $description = 'مكان هادي بإضاءة دافية وقعدات مريحة، مناسب للقاءات الأصدقاء والعيلة، وفيه مشروبات وأكل متنوع بأسعار معقولة وخدمة محترمة.';

        // ثلاث أنماط مواعيد: كافيه بعد نص الليل، نهاري، 24 ساعة
        $patterns = [
            array_fill_keys(OpeningHours::DAYS, ['state' => 'open', 'slots' => [['09:00', '01:00']]]),
            array_fill_keys(OpeningHours::DAYS, ['state' => 'open', 'slots' => [['09:00', '17:00']]]),
            array_fill_keys(OpeningHours::DAYS, ['state' => '24h']),
        ];

        $places = [];
        $hours = [];
        $pivotCategories = [];
        $pivotAttributes = [];

        for ($n = 1; $n <= self::PLACES; $n++) {
            // التوزيع حوالين المركز: ~44 كم عرض × ~68 كم طول
            $lat = round(self::CENTER[0] + (mt_rand(-2200, 2200) / 10000), 7);
            $lng = round(self::CENTER[1] + (mt_rand(-3500, 3500) / 10000), 7);

            $status = match (true) {
                $n % 50 === 0 => 'hidden',
                $n % 97 === 0 => 'draft',
                $n % 211 === 0 => 'closed',
                default => 'active',
            };
            $tier = match (true) {
                $n % 100 === 0 => 'premium',
                $n % 25 === 0 => 'featured',
                default => 'free',
            };
            $priceMin = mt_rand(20, 300);
            $priceMax = $priceMin + mt_rand(20, 400);
            $pattern = $n % 7 === 0 ? 2 : ($n % 3 === 0 ? 1 : 0);
            $primary = $categoryIds[$n % count($categoryIds)];
            $ratingCount = mt_rand(0, 300);

            $places[] = [
                'slug' => 'bench-'.$n,
                'name_ar' => $words[$n % 10].' '.$suffixes[intdiv($n, 10) % 10].' '.$n,
                'name_en' => 'Bench Place '.$n,
                'description_ar' => $description,
                'primary_category_id' => $primary,
                'city_id' => $cityId,
                'area_id' => $areaId,
                'address_ar' => 'شارع '.$n.'، التجمع الخامس',
                'latitude' => $lat,
                'longitude' => $lng,
                'location' => PlaceLocation::point($lat, $lng),
                'price_min' => $priceMin,
                'price_max' => $priceMax,
                'price_range' => $priceMax < 250 ? 'budget' : ($priceMax < 600 ? 'mid' : 'premium'),
                'opening_hours' => json_encode($patterns[$pattern]),
                'status' => $status,
                'published_at' => $status === 'draft' ? null : now()->subDays(mt_rand(1, 400)),
                'closed_at' => $status === 'closed' ? now()->subDays(20)->toDateString() : null,
                'listing_tier' => $tier,
                'tier_rank' => ['premium' => 1, 'featured' => 2, 'free' => 3][$tier],
                'rating_avg' => $ratingCount === 0 ? 0 : round(mt_rand(30, 50) / 10, 2),
                'rating_count' => $ratingCount,
                'created_at' => now()->subDays(mt_rand(1, 450)),
                'updated_at' => now(),
            ];

            $pivotCategories[] = ['place_id' => $n, 'category_id' => $primary];
            if ($n % 3 === 0) {
                $other = $categoryIds[($n + 1) % count($categoryIds)];
                if ($other !== $primary) {
                    $pivotCategories[] = ['place_id' => $n, 'category_id' => $other];
                }
            }

            foreach ((array) array_rand(array_flip($attributeIds), 3) as $attributeId) {
                $pivotAttributes[] = ['place_id' => $n, 'attribute_id' => $attributeId];
            }

            foreach (OpeningHours::intervals($patterns[$pattern]) as [$opens, $closes]) {
                $hours[] = ['place_id' => $n, 'is_ramadan' => false, 'opens_minute' => $opens, 'closes_minute' => $closes];
            }
        }

        // الـ ids من 1 لأن الجدول فاضي بعد migrate:fresh، فالـ pivots تقدر تشاور على $n
        foreach (array_chunk($places, 250) as $chunk) {
            DB::table('places')->insert($chunk);
        }
        foreach (array_chunk($pivotCategories, 1000) as $chunk) {
            DB::table('place_category')->insert($chunk);
        }
        foreach (array_chunk($pivotAttributes, 1000) as $chunk) {
            DB::table('attribute_place')->insert($chunk);
        }
        foreach (array_chunk($hours, 1000) as $chunk) {
            DB::table('place_hours')->insert($chunk);
        }

        PlaceSearchText::rebuildAll();

        DB::statement('ANALYZE TABLE places, place_hours, place_category, attribute_place');
    }
}
