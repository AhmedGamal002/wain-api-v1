<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Support\PlaceLocation;
use Database\Seeders\DemoPlaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * GET /places/map (03 القسم 5.1، 02 F6): pins المنشور جوه المستطيل بنفس فلاتر `/places`، لحد 200، والمدفوع الأول.
 */
class MapPlacesApiTest extends TestCase
{
    use RefreshDatabase;

    private const AR = ['Accept-Language' => 'ar'];

    // يغطي كل الأماكن التجريبية (التجمع الخامس)
    private const ALL = '31.30,29.90,31.70,30.20';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoPlaceSeeder::class);
    }

    private function map(string $bbox, string $query = ''): TestResponse
    {
        return $this->getJson('/api/v1/places/map?bbox='.$bbox.($query === '' ? '' : "&{$query}"), self::AR);
    }

    /**
     * المنشور جوه المستطيل، من الداتابيز مباشرة (المرجع للمقارنة).
     *
     * @return list<string>
     */
    private function expectedSlugs(float $minLng, float $minLat, float $maxLng, float $maxLat): array
    {
        return DB::table('places')
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->whereBetween('longitude', [$minLng, $maxLng])
            ->whereBetween('latitude', [$minLat, $maxLat])
            ->orderBy('slug')
            ->pluck('slug')
            ->all();
    }

    /**
     * @param  TestResponse<JsonResponse>  $response
     * @return list<string>
     */
    private function slugs(TestResponse $response): array
    {
        $slugs = array_column($response->json('data'), 'slug');
        sort($slugs);

        return $slugs;
    }

    public function test_pin_has_only_the_documented_light_fields(): void
    {
        $response = $this->map(self::ALL)->assertOk();
        $pins = $response->json('data');

        $this->assertNotEmpty($pins);
        $this->assertEqualsCanonicalizing(
            ['id', 'slug', 'latitude', 'longitude', 'category_slug', 'listing_tier'],
            array_keys($pins[0]),
        );
        $this->assertIsFloat($pins[0]['latitude']);
        $this->assertIsFloat($pins[0]['longitude']);
        $this->assertContains($pins[0]['listing_tier'], ['free', 'featured', 'premium']);
        // قوائم الخريطة من غير meta (06 القسم 1)
        $this->assertSame(['data'], array_keys($response->json()));

        // مفيش حاجة داخلية ولا ثقيلة
        $body = $response->getContent();
        foreach (['internal_notes', 'status_reason', 'created_by', 'search_text', 'name_ar', 'cover', 'distance_km'] as $leak) {
            $this->assertStringNotContainsString($leak, $body);
        }
    }

    public function test_matches_a_brute_force_bounding_box_query_for_several_boxes(): void
    {
        $lngs = DB::table('places')->where('status', 'active')->orderBy('longitude')->pluck('longitude')->map(fn ($v) => (float) $v)->all();
        $median = $lngs[intdiv(count($lngs), 2)];

        $boxes = [
            [31.30, 29.90, 31.70, 30.20],
            [31.30, 29.90, $median, 30.20],
            [$median, 29.90, 31.70, 30.20],
            [31.465, 30.025, 31.475, 30.035],
            [31.40, 30.00, 31.50, 30.06],
        ];

        foreach ($boxes as [$minLng, $minLat, $maxLng, $maxLat]) {
            $bbox = "{$minLng},{$minLat},{$maxLng},{$maxLat}";

            $this->assertSame(
                $this->expectedSlugs($minLng, $minLat, $maxLng, $maxLat),
                $this->slugs($this->map($bbox)->assertOk()),
                "bbox {$bbox}",
            );
        }
    }

    public function test_never_returns_unpublished_or_deleted_places(): void
    {
        $unpublished = DB::table('places')->where('status', '!=', 'active')->pluck('slug')->all();
        $this->assertNotEmpty($unpublished);

        $deleted = DB::table('places')->where('status', 'active')->orderBy('id')->first();
        DB::table('places')->where('id', $deleted->id)->update(['deleted_at' => now()]);

        $slugs = $this->slugs($this->map(self::ALL)->assertOk());

        $this->assertSame([], array_intersect($unpublished, $slugs));
        $this->assertNotContains($deleted->slug, $slugs);
    }

    public function test_a_box_without_places_returns_an_empty_list(): void
    {
        // أسوان
        $this->map('32.80,24.00,33.00,24.20')
            ->assertOk()
            ->assertExactJson(['data' => []]);
    }

    public function test_category_pin_slug_matches_the_primary_category(): void
    {
        // أسماء مستعارة: الاتنين عمودهم `slug` فـ pluck بالاسم الكامل بيرجّع slug المكان للاتنين
        $expected = DB::table('places')
            ->join('categories', 'categories.id', '=', 'places.primary_category_id')
            ->where('places.status', 'active')
            ->select('places.slug as place_slug', 'categories.slug as category_slug')
            ->get()
            ->pluck('category_slug', 'place_slug')
            ->all();

        foreach ($this->map(self::ALL)->assertOk()->json('data') as $pin) {
            $this->assertSame($expected[$pin['slug']], $pin['category_slug']);
        }
    }

    public function test_applies_the_same_filters_as_the_list(): void
    {
        $categorySlug = DB::table('places')
            ->join('categories', 'categories.id', '=', 'places.primary_category_id')
            ->where('places.status', 'active')
            ->groupBy('categories.slug')
            ->orderByRaw('COUNT(*) DESC')
            ->value('categories.slug');

        Carbon::setTestNow(Carbon::parse('2026-10-14', 'Africa/Cairo')->setTime(12, 0));

        try {
            foreach (["categories[]={$categorySlug}", "categories[]={$categorySlug}&open_now=1", 'price_max=150', 'rating_min=4'] as $filters) {
                $pins = $this->map(self::ALL, $filters)->assertOk()->json('data');
                $count = $this->getJson('/api/v1/places/count?'.$filters, self::AR)->assertOk()->json('count');

                $this->assertCount($count, $pins, $filters);
            }
        } finally {
            Carbon::setTestNow();
        }

        // الفلتر بيضيّق فعلاً
        $all = count($this->map(self::ALL)->json('data'));
        $filtered = count($this->map(self::ALL, "categories[]={$categorySlug}")->json('data'));
        $this->assertGreaterThan(0, $filtered);
        $this->assertLessThanOrEqual($all, $filtered);
    }

    public function test_caps_at_200_pins_with_paid_places_first(): void
    {
        $template = DB::table('places')->where('status', 'active')->orderBy('id')->first();
        $now = now();

        $rows = [];
        foreach (range(1, 210) as $n) {
            $premium = $n <= 3;
            $rows[] = [
                'slug' => "map-cap-{$n}",
                'name_ar' => "مكان {$n}",
                'name_en' => "Place {$n}",
                'primary_category_id' => $template->primary_category_id,
                'city_id' => $template->city_id,
                'area_id' => $template->area_id,
                'address_ar' => 'شارع التسعين',
                'latitude' => 30.03 + ($n % 10) / 1000,
                'longitude' => 31.47 + intdiv($n, 10) / 1000,
                'location' => PlaceLocation::point(30.03 + ($n % 10) / 1000, 31.47 + intdiv($n, 10) / 1000),
                'status' => 'active',
                'listing_tier' => $premium ? 'premium' : 'free',
                'tier_rank' => $premium ? 1 : 3,
                'published_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        DB::table('places')->insert($rows);

        $response = $this->map(self::ALL)->assertOk();
        $pins = $response->json('data');

        $this->assertCount(200, $pins);

        // الباقة بترتّب الأول: الـ premium كلهم موجودين، ومفيش مجاني قبل مدفوع
        $slugs = array_column($pins, 'slug');
        foreach (['map-cap-1', 'map-cap-2', 'map-cap-3'] as $slug) {
            $this->assertContains($slug, $slugs);
        }
        $rank = ['premium' => 1, 'featured' => 2, 'free' => 3];
        $ranks = array_map(fn ($pin) => $rank[$pin['listing_tier']], $pins);
        $sorted = $ranks;
        sort($sorted);
        $this->assertSame($sorted, $ranks);

        // من غير تكرار
        $this->assertCount(200, array_unique($slugs));
    }

    public function test_validation(): void
    {
        // من غير bbox: 422 (مش 404 ولا slug)
        $this->getJson('/api/v1/places/map', self::AR)
            ->assertStatus(422)
            ->assertJsonValidationErrors('bbox')
            ->assertJsonPath('code', 'VALIDATION_ERROR');

        $invalid = [
            'ناقص' => '31.3,29.9,31.7',
            'زيادة' => '31.3,29.9,31.7,30.2,5',
            'مش أرقام' => 'a,b,c,d',
            'الأصغر والأكبر مبدّلين في الطول' => '31.7,29.9,31.3,30.2',
            'الأصغر والأكبر مبدّلين في العرض' => '31.3,30.2,31.7,29.9',
            'مساحة صفر' => '31.3,29.9,31.3,30.2',
            'طول برّه المدى' => '181,29.9,182,30.2',
            'عرض برّه المدى' => '31.3,91,31.7,92',
            'علمي' => '1e1,29.9,31.7,30.2',
        ];

        foreach ($invalid as $label => $bbox) {
            $response = $this->map($bbox);

            $this->assertSame(422, $response->getStatusCode(), $label);
            $response->assertJsonValidationErrors('bbox')->assertJsonPath('code', 'VALIDATION_ERROR');
        }

        // الفلاتر نفس قواعد /places
        $this->map(self::ALL, 'categories[]=not-a-category')->assertStatus(422)->assertJsonValidationErrors('categories.0');
        $this->map(self::ALL, 'price_min=300&price_max=100')->assertStatus(422)->assertJsonValidationErrors('price_max');
    }

    public function test_sort_and_pagination_parameters_are_ignored_not_rejected(): void
    {
        // الموبايل بيبعت نفس query القايمة، فمش لازم يعمل 422
        $this->map(self::ALL, 'sort=nearest&page=3&per_page=10&lat=30.03&lng=31.47')
            ->assertOk()
            ->assertJsonCount(count($this->expectedSlugs(31.30, 29.90, 31.70, 30.20)), 'data');
    }

    public function test_works_with_a_token_and_without_one(): void
    {
        $this->map(self::ALL)->assertOk();

        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/places/map?bbox='.self::ALL, self::AR)->assertOk();
    }
}
