<?php

namespace Tests\Feature\Api;

use App\Support\PlaceLocation;
use Database\Seeders\DemoPlaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * GET /places/nearby (03 القسم 5.1، 7.5): المنشور جوه نصف القطر مرتب بالأقرب.
 */
class NearbyPlacesApiTest extends TestCase
{
    use RefreshDatabase;

    private const AR = ['Accept-Language' => 'ar'];

    // قلب التجمع الخامس
    private const LAT = 30.0300;

    private const LNG = 31.4700;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoPlaceSeeder::class);
    }

    private function nearby(string $query = ''): TestResponse
    {
        $base = 'lat='.self::LAT.'&lng='.self::LNG;

        return $this->getJson('/api/v1/places/nearby?'.$base.($query === '' ? '' : "&{$query}"), self::AR);
    }

    /**
     * الأماكن المنشورة جوه نصف القطر بالكيلو، من الداتابيز مباشرة (المرجع للمقارنة).
     *
     * @return list<string>
     */
    private function expectedSlugs(float $radiusKm, int $limit = 30): array
    {
        $distance = PlaceLocation::distanceMetersSql(self::LAT, self::LNG);

        return DB::table('places')
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->whereRaw("{$distance} <= ?", [$radiusKm * 1000])
            ->orderByRaw($distance)
            ->orderBy('id')
            ->limit($limit)
            ->pluck('slug')
            ->all();
    }

    public function test_returns_cards_with_distance_sorted_nearest_first(): void
    {
        $response = $this->nearby('radius=30&limit=30')->assertOk();

        $cards = $response->json('data');
        $this->assertNotEmpty($cards);
        $this->assertArrayNotHasKey('meta', $response->json());

        $distances = array_column($cards, 'distance_km');
        $this->assertNotContains(null, $distances);
        $sorted = $distances;
        sort($sorted);
        $this->assertSame($sorted, $distances);

        foreach (['id', 'slug', 'name', 'primary_category', 'area', 'cover', 'price', 'rating', 'is_open_now', 'distance_km'] as $key) {
            $this->assertArrayHasKey($key, $cards[0]);
        }
    }

    public function test_matches_a_brute_force_distance_query_for_several_radii(): void
    {
        foreach ([0.5, 1.0, 2.0, 5.0, 15.0] as $radius) {
            $slugs = array_column($this->nearby("radius={$radius}&limit=30")->assertOk()->json('data'), 'slug');

            $this->assertSame($this->expectedSlugs($radius), $slugs, "radius {$radius} km");
        }
    }

    public function test_limit_and_default_radius(): void
    {
        $this->assertCount(3, $this->nearby('radius=30&limit=3')->json('data'));

        // من غير radius = 5 كم
        $default = array_column($this->nearby()->assertOk()->json('data'), 'slug');
        $this->assertSame($this->expectedSlugs(5.0, 10), $default);
    }

    public function test_never_returns_unpublished_places(): void
    {
        $unpublished = DB::table('places')->where('status', '!=', 'active')->pluck('slug')->all();
        $this->assertNotEmpty($unpublished);

        $slugs = array_column($this->nearby('radius=30&limit=30')->json('data'), 'slug');

        $this->assertSame([], array_intersect($unpublished, $slugs));
    }

    public function test_far_away_point_returns_an_empty_list(): void
    {
        // أسوان، بعيدة مئات الكيلومترات عن التجمع
        $this->getJson('/api/v1/places/nearby?lat=24.0889&lng=32.8998&radius=30', self::AR)
            ->assertOk()
            ->assertExactJson(['data' => []]);
    }

    public function test_validation(): void
    {
        $this->getJson('/api/v1/places/nearby', self::AR)
            ->assertStatus(422)->assertJsonValidationErrors(['lat', 'lng']);

        $this->nearby('radius=31')->assertStatus(422)->assertJsonValidationErrors('radius');
        $this->nearby('radius=0')->assertStatus(422)->assertJsonValidationErrors('radius');
        $this->nearby('limit=31')->assertStatus(422)->assertJsonValidationErrors('limit');
        $this->getJson('/api/v1/places/nearby?lat=95&lng=31', self::AR)->assertStatus(422)->assertJsonValidationErrors('lat');
    }

    public function test_bounding_box_covers_the_radius_and_uses_lng_then_lat(): void
    {
        $sql = PlaceLocation::boundingBoxSql(self::LAT, self::LNG, 5.0);

        $this->assertStringStartsWith("MBRContains(ST_GeomFromText('POLYGON((", $sql);
        $this->assertStringContainsString('`location`', $sql);

        preg_match('/POLYGON\(\(([^)]+)\)\)/', $sql, $m);
        $points = array_map(fn ($pair) => array_map('floatval', explode(' ', trim($pair))), explode(',', $m[1]));

        // أول رقم في كل نقطة هو الطول (31.x) والتاني العرض (30.x)
        foreach ($points as [$lng, $lat]) {
            $this->assertEqualsWithDelta(31.47, $lng, 0.2);
            $this->assertEqualsWithDelta(30.03, $lat, 0.2);
        }
        // مغلق: أول نقطة = آخر نقطة
        $this->assertSame($points[0], $points[count($points) - 1]);
        // نصف القطر 5 كم ≈ 0.045 درجة عرض في كل اتجاه، فارتفاع المستطيل كله ≈ 0.090
        $this->assertEqualsWithDelta(0.0904, $points[2][1] - $points[0][1], 0.006);
    }
}
