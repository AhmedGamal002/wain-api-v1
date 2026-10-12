<?php

namespace Tests\Feature\Api;

use Database\Seeders\DemoPlaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * GET /places (03 القسم 5): قائمة الأماكن المنشورة بشكل الكارت، pagination، ترتيب، ومسافة.
 * بيشتغل على الـ 30 مكان التجريبي (26 منشور + مخفي ومغلق ومسودة وقيد مراجعة متعمّدين).
 */
class PlaceListApiTest extends TestCase
{
    use RefreshDatabase;

    private const AR = ['Accept-Language' => 'ar'];

    // نقطة جوه التجمع الخامس
    private const LAT = 30.0300;

    private const LNG = 31.4700;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoPlaceSeeder::class);
    }

    private function publishedCount(): int
    {
        return DB::table('places')->where('status', 'active')->whereNull('deleted_at')->count();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function allCards(string $query = ''): array
    {
        $cards = [];
        $page = 1;

        do {
            $json = $this->getJson("/api/v1/places?per_page=50&page={$page}".($query === '' ? '' : "&{$query}"), self::AR)
                ->assertOk()
                ->json();
            $cards = array_merge($cards, $json['data']);
            $page++;
        } while ($page <= $json['meta']['last_page']);

        return $cards;
    }

    public function test_returns_only_published_places(): void
    {
        $this->assertGreaterThan(0, DB::table('places')->where('status', '!=', 'active')->count());

        $slugs = array_column($this->allCards(), 'slug');
        $hidden = DB::table('places')->where('status', '!=', 'active')->pluck('slug')->all();

        $this->assertCount($this->publishedCount(), $slugs);
        $this->assertSame([], array_intersect($slugs, $hidden));
    }

    public function test_card_has_the_documented_shape_and_no_internal_fields(): void
    {
        $card = $this->getJson('/api/v1/places?per_page=1', self::AR)->assertOk()->json('data.0');

        $this->assertEqualsCanonicalizing([
            'id', 'slug', 'name', 'primary_category', 'area', 'cover', 'price', 'rating', 'listing_tier',
            'is_open_now', 'distance_km', 'latitude', 'longitude', 'is_saved',
        ], array_keys($card));

        $this->assertSame(['slug', 'name', 'icon', 'color'], array_keys($card['primary_category']));
        $this->assertSame(['slug', 'name'], array_keys($card['area']));
        $this->assertSame(['thumb', 'medium'], array_keys($card['cover']));
        $this->assertSame(['min', 'max', 'range'], array_keys($card['price']));
        $this->assertSame(['avg', 'count'], array_keys($card['rating']));
        $this->assertStringStartsWith('http', $card['cover']['thumb']);
        $this->assertNull($card['distance_km']);
        $this->assertFalse($card['is_saved']);
    }

    public function test_never_leaks_internal_fields(): void
    {
        $body = $this->getJson('/api/v1/places?per_page=50', self::AR)->assertOk()->getContent();

        foreach (['internal_notes', 'status_reason', 'created_by', 'updated_by', 'claimed_by', 'search_text', 'path'] as $field) {
            $this->assertStringNotContainsString("\"{$field}\"", $body);
        }
    }

    public function test_pagination_meta_and_no_overlap_between_pages(): void
    {
        $total = $this->publishedCount();

        $first = $this->getJson('/api/v1/places?per_page=10&page=1', self::AR)->assertOk();
        $second = $this->getJson('/api/v1/places?per_page=10&page=2', self::AR)->assertOk();

        $first->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('meta.total', $total)
            ->assertJsonPath('meta.last_page', (int) ceil($total / 10));
        $this->assertSame(['current_page', 'last_page', 'per_page', 'total'], array_keys($first->json('meta')));
        $this->assertCount(10, $first->json('data'));

        $this->assertSame([], array_intersect(
            array_column($first->json('data'), 'id'),
            array_column($second->json('data'), 'id'),
        ));
    }

    public function test_default_per_page_is_20_and_max_is_50(): void
    {
        $this->getJson('/api/v1/places', self::AR)->assertOk()->assertJsonPath('meta.per_page', 20);
        $this->getJson('/api/v1/places?per_page=51', self::AR)->assertStatus(422)->assertJsonValidationErrors('per_page');
        $this->getJson('/api/v1/places?per_page=0', self::AR)->assertStatus(422);
    }

    public function test_recommended_orders_paid_tiers_first_and_rated_free_places_before_new_ones(): void
    {
        $cards = $this->allCards();
        $tiers = array_column($cards, 'listing_tier');

        $rank = ['premium' => 1, 'featured' => 2, 'free' => 3];
        $ranks = array_map(fn ($tier) => $rank[$tier], $tiers);
        $sorted = $ranks;
        sort($sorted);
        $this->assertSame($sorted, $ranks, 'tiers must come premium, featured, then free');
        $this->assertContains('premium', $tiers);
        $this->assertContains('featured', $tiers);

        $free = array_values(array_filter($cards, fn ($c) => $c['listing_tier'] === 'free'));
        $rated = array_values(array_filter($free, fn ($c) => $c['rating']['count'] >= 3));
        $this->assertNotEmpty($rated);
        $this->assertSame($rated, array_slice($free, 0, count($rated)), 'places with 3+ ratings come first');

        $avgs = array_column(array_column($rated, 'rating'), 'avg');
        $descending = $avgs;
        rsort($descending);
        $this->assertSame($descending, $avgs);
    }

    public function test_recommended_order_is_stable_within_a_day_and_rotates_paid_places_across_days(): void
    {
        $paidOrder = function (string $day): array {
            Carbon::setTestNow(Carbon::parse($day, 'Africa/Cairo')->setTime(12, 0));
            $paid = array_filter($this->allCards(), fn ($c) => $c['listing_tier'] !== 'free');

            return array_values(array_column($paid, 'slug'));
        };

        $first = $paidOrder('2026-10-12');
        $this->assertSame($first, $paidOrder('2026-10-12'));

        $orders = [$first];
        foreach (['2026-10-13', '2026-10-14', '2026-10-15', '2026-10-16'] as $day) {
            $orders[] = $paidOrder($day);
        }
        Carbon::setTestNow();

        // نفس المجموعة دايماً، بس بترتيب بيتبدّل
        foreach ($orders as $order) {
            $this->assertEqualsCanonicalizing($first, $order);
        }
        $this->assertGreaterThan(1, count(array_unique(array_map('json_encode', $orders))));
    }

    public function test_sort_by_rating_and_newest(): void
    {
        $byRating = array_column(array_column($this->allCards('sort=rating'), 'rating'), 'avg');
        $descending = $byRating;
        rsort($descending);
        $this->assertSame($descending, $byRating);

        $newest = $this->allCards('sort=newest');
        $publishedAt = DB::table('places')->whereIn('slug', array_column($newest, 'slug'))->pluck('published_at', 'slug');
        $dates = array_map(fn ($c) => $publishedAt[$c['slug']], $newest);
        $expected = $dates;
        rsort($expected);
        $this->assertSame($expected, $dates);
    }

    public function test_distance_is_returned_with_lat_lng_and_nearest_sorts_by_it(): void
    {
        $cards = $this->allCards('sort=nearest&lat='.self::LAT.'&lng='.self::LNG);

        $distances = array_column($cards, 'distance_km');
        $this->assertNotContains(null, $distances);

        $ascending = $distances;
        sort($ascending);
        $this->assertSame($ascending, $distances);

        // المسافات داخل مدينة واحدة، فلازم تبقى كام كيلو مش مئات
        $this->assertLessThan(60, max($distances));
    }

    public function test_nearest_requires_a_location_and_location_must_be_a_pair(): void
    {
        $this->getJson('/api/v1/places?sort=nearest', self::AR)
            ->assertStatus(422)->assertJsonValidationErrors(['lat', 'lng']);

        $this->getJson('/api/v1/places?lat=30.03', self::AR)
            ->assertStatus(422)->assertJsonValidationErrors('lng');

        $this->getJson('/api/v1/places?lat=300&lng=31.47', self::AR)
            ->assertStatus(422)->assertJsonValidationErrors('lat');
    }

    public function test_area_filter(): void
    {
        $this->assertCount($this->publishedCount(), $this->allCards('area=new-cairo'));

        $this->getJson('/api/v1/places?area=atlantis', self::AR)
            ->assertStatus(422)->assertJsonValidationErrors('area');
    }

    public function test_categories_filter_is_or_across_the_chosen_categories(): void
    {
        $cafes = $this->allCards('categories[]=cafes');
        $this->assertNotEmpty($cafes);

        // المكان بيظهر لو "كافيهات" أي تصنيف من تصنيفاته (الرئيسي أو الإضافي)
        $cafeCategoryId = DB::table('categories')->where('slug', 'cafes')->value('id');
        $expected = DB::table('place_category')
            ->join('places', 'places.id', '=', 'place_category.place_id')
            ->where('place_category.category_id', $cafeCategoryId)
            ->where('places.status', 'active')
            ->whereNull('places.deleted_at')
            ->pluck('places.id')
            ->all();
        $this->assertEqualsCanonicalizing($expected, array_column($cafes, 'id'));

        $rooftops = $this->allCards('categories[]=rooftops');
        $both = $this->allCards('categories[]=cafes&categories[]=rooftops');

        $this->assertEqualsCanonicalizing(
            array_values(array_unique(array_merge(array_column($cafes, 'id'), array_column($rooftops, 'id')))),
            array_column($both, 'id'),
        );
    }

    public function test_categories_filter_never_exposes_unpublished_places_and_rejects_unknown_slugs(): void
    {
        $unpublished = DB::table('places')->where('status', '!=', 'active')->pluck('slug')->all();
        $allCategories = DB::table('categories')->pluck('slug')->map(fn ($s) => 'categories[]='.$s)->implode('&');

        $this->assertSame([], array_intersect($unpublished, array_column($this->allCards($allCategories), 'slug')));

        $this->getJson('/api/v1/places?categories[]=nope', self::AR)
            ->assertStatus(422)
            ->assertJsonValidationErrors('categories.0');
    }

    public function test_unknown_sort_is_rejected_in_the_request_language(): void
    {
        $this->getJson('/api/v1/places?sort=random', self::AR)
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR')
            ->assertJsonPath('errors.sort.0', 'الترتيب المختار مش صحيح.');
    }

    public function test_names_follow_accept_language_with_arabic_fallback(): void
    {
        $slug = 'el-mahatta-coffee';
        $this->assertSame('قهوة المحطة', $this->placeName($slug, 'ar'));
        $this->assertSame('El Mahatta Coffee', $this->placeName($slug, 'en'));

        // لو الإنجليزي فاضي بيرجع العربي
        DB::table('places')->where('slug', $slug)->update(['name_en' => '']);
        $this->assertSame('قهوة المحطة', $this->placeName($slug, 'en'));
    }

    private function placeName(string $slug, string $locale): string
    {
        $cards = [];
        $page = 1;

        do {
            $json = $this->getJson("/api/v1/places?per_page=50&page={$page}", ['Accept-Language' => $locale])->json();
            $cards = array_merge($cards, $json['data']);
            $page++;
        } while ($page <= $json['meta']['last_page']);

        $card = collect($cards)->firstWhere('slug', $slug);
        $this->assertNotNull($card);

        return $card['name'];
    }

    public function test_cover_is_null_when_the_photo_is_not_ready(): void
    {
        $placeId = DB::table('places')->where('slug', 'el-mahatta-coffee')->value('id');
        DB::table('place_photos')
            ->where('id', DB::table('places')->where('id', $placeId)->value('cover_photo_id'))
            ->update(['status' => 'processing']);

        $card = collect($this->allCards())->firstWhere('slug', 'el-mahatta-coffee');

        $this->assertNull($card['cover']);
    }

    public function test_soft_deleted_places_are_not_listed(): void
    {
        DB::table('places')->where('slug', 'el-mahatta-coffee')->update(['deleted_at' => now()]);

        $this->assertNotContains('el-mahatta-coffee', array_column($this->allCards(), 'slug'));
    }
}
