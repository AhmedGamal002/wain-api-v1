<?php

namespace Tests\Feature\Api;

use App\Http\Requests\IndexPlacesRequest;
use App\Models\Category;
use App\Models\Place;
use App\Support\PlaceListCache;
use Database\Seeders\DemoPlaceSeeder;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * cache قوائم `GET /places` (06 القسم 5): ترتيب الـ ids وعدد النتايج لمدة 60 ثانية بمفتاح فيه نسخة بتتغيّر مع
 * أي تعديل. بيشتغل على `file` store فعلي (مش `array`): الـ array مابيعملش serialize فبيخبّي مشاكل التخزين.
 */
class PlaceListCacheTest extends TestCase
{
    use RefreshDatabase;

    private const AR = ['Accept-Language' => 'ar'];

    private string $cachePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoPlaceSeeder::class);

        $this->cachePath = storage_path('framework/testing/cache-'.uniqid());
        config(['cache.default' => 'file', 'cache.stores.file.path' => $this->cachePath]);
        Cache::purge();
    }

    protected function tearDown(): void
    {
        Cache::flush();
        Cache::purge();
        (new Filesystem)->deleteDirectory($this->cachePath);

        parent::tearDown();
    }

    /**
     * عدد استعلامات الداتابيز لطلب واحد.
     */
    private function queriesFor(string $url): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->getJson($url, self::AR)->assertOk();

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    /**
     * @return list<string>
     */
    private function slugs(string $url): array
    {
        return array_column($this->getJson($url, self::AR)->assertOk()->json('data'), 'slug');
    }

    public function test_the_second_identical_request_skips_the_listing_queries(): void
    {
        $first = $this->queriesFor('/api/v1/places?per_page=10');
        $second = $this->queriesFor('/api/v1/places?per_page=10');

        // من غير count واستعلام الـ ids: الكروت وعلاقاتها بس
        $this->assertLessThan($first, $second);
    }

    public function test_a_cached_page_is_identical_to_a_fresh_one(): void
    {
        $fresh = $this->getJson('/api/v1/places?per_page=7&page=2', self::AR)->assertOk()->json();
        $cached = $this->getJson('/api/v1/places?per_page=7&page=2', self::AR)->assertOk()->json();

        $this->assertSame($fresh, $cached);
        $this->assertCount(7, $cached['data']);
        $this->assertSame(2, $cached['meta']['current_page']);
    }

    public function test_changing_a_place_makes_the_next_request_fresh(): void
    {
        $url = '/api/v1/places?per_page=50';
        $before = $this->getJson($url, self::AR)->assertOk()->json();
        $victim = $before['data'][0]['slug'];

        // Eloquent بيطلق PlaceChanged فنسخة الـ cache بتتغيّر
        Place::query()->where('slug', $victim)->firstOrFail()->update(['status' => 'hidden', 'status_reason' => 'شكوى']);

        $after = $this->getJson($url, self::AR)->assertOk()->json();

        $this->assertNotContains($victim, array_column($after['data'], 'slug'));
        $this->assertSame($before['meta']['total'] - 1, $after['meta']['total']);
    }

    public function test_a_place_hidden_behind_the_events_back_still_disappears_from_the_page(): void
    {
        $url = '/api/v1/places?per_page=50';
        $victim = $this->getJson($url, self::AR)->json('data.0.slug');

        // DB::table بيتخطى الـ Observer (من غير PlaceChanged): الـ ids لسه في الـ cache، بس الكارت ما بيرجعش
        DB::table('places')->where('slug', $victim)->update(['status' => 'hidden']);

        $this->assertNotContains($victim, $this->slugs($url));
    }

    public function test_the_distance_is_computed_for_each_request_not_cached(): void
    {
        $near = $this->getJson('/api/v1/places?per_page=3&lat=30.03&lng=31.47', self::AR)->assertOk()->json('data');
        $far = $this->getJson('/api/v1/places?per_page=3&lat=30.10&lng=31.60', self::AR)->assertOk()->json('data');

        // نفس الـ ids (الترتيب المقترح) بس المسافات بتاعة كل نقطة
        $this->assertSame(array_column($near, 'slug'), array_column($far, 'slug'));
        $this->assertNotSame(array_column($near, 'distance_km'), array_column($far, 'distance_km'));
        $this->assertNotContains(null, array_column($far, 'distance_km'));
    }

    public function test_nearest_open_now_and_deep_pages_are_not_cached(): void
    {
        foreach ([
            '/api/v1/places?sort=nearest&lat=30.03&lng=31.47&per_page=5',
            '/api/v1/places?open_now=1&per_page=5',
            '/api/v1/places?per_page=2&page='.(PlaceListCache::MAX_CACHED_PAGE + 1),
        ] as $url) {
            $first = $this->queriesFor($url);
            $second = $this->queriesFor($url);

            $this->assertSame($first, $second, "{$url} was cached");
        }
    }

    public function test_every_filter_has_its_own_entry(): void
    {
        $all = $this->getJson('/api/v1/places?per_page=50', self::AR)->json('meta.total');
        $cafes = $this->getJson('/api/v1/places?per_page=50&categories[]=cafes', self::AR)->json('meta.total');
        $rating = $this->getJson('/api/v1/places?per_page=50&rating_min=4.5', self::AR)->json('meta.total');

        $this->assertLessThan($all, $cafes);
        $this->assertLessThan($all, $rating);

        // ومرّة تانية من الـ cache: نفس الأرقام
        $this->assertSame($cafes, $this->getJson('/api/v1/places?per_page=50&categories[]=cafes', self::AR)->json('meta.total'));
        $this->assertSame($all, $this->getJson('/api/v1/places?per_page=50', self::AR)->json('meta.total'));
    }

    public function test_the_order_of_filter_values_does_not_make_a_new_entry(): void
    {
        $this->getJson('/api/v1/places?categories[]=cafes&categories[]=restaurants', self::AR)->assertOk();
        $queries = $this->queriesFor('/api/v1/places?categories[]=restaurants&categories[]=cafes');

        $this->getJson('/api/v1/places?categories[]=cafes&categories[]=restaurants', self::AR)->assertOk();
        $cached = $this->queriesFor('/api/v1/places?categories[]=cafes&categories[]=restaurants');

        $this->assertSame($cached, $queries);
    }

    public function test_changing_a_catalog_item_changes_the_version(): void
    {
        $before = PlaceListCache::version();

        $category = Category::query()->firstOrFail();
        $category->update(['name_ar' => $category->name_ar.' (جديد)']);

        $this->assertNotSame($before, PlaceListCache::version());
    }

    public function test_the_listing_survives_a_real_file_cache_round_trip(): void
    {
        $url = '/api/v1/places?per_page=5&categories[]=cafes';

        $fresh = $this->getJson($url, self::AR)->assertOk()->json();
        $this->assertTrue(Cache::has(PlaceListCache::key($this->requestFor($url))));

        // arrays وscalars بس: مفيش __PHP_Incomplete_Class
        $stored = Cache::get(PlaceListCache::key($this->requestFor($url)));
        $this->assertIsArray($stored);
        $this->assertSame($fresh['meta'], $stored['meta']);
        $this->assertSame(array_column($fresh['data'], 'id'), $stored['ids']);
    }

    private function requestFor(string $url): IndexPlacesRequest
    {
        $request = IndexPlacesRequest::create($url, 'GET');
        $request->setContainer(app())->setRedirector(app('redirect'));
        $request->validateResolved();

        return $request;
    }
}
