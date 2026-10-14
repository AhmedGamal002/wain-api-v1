<?php

namespace Tests\Feature\Api;

use App\Http\Controllers\Api\V1\HomeController;
use Database\Seeders\DemoPlaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * GET /home (03 القسم 5.1، 02 F3): المجموعات (فاضية لحد P6) و«قريب منك» في request واحد. بيشتغل على الـ 30 مكان
 * التجريبي (26 منشور في التجمع الخامس + مخفي ومغلق ومسودة وقيد مراجعة متعمّدين).
 */
class HomeApiTest extends TestCase
{
    use RefreshDatabase;

    private const AR = ['Accept-Language' => 'ar'];

    private const EN = ['Accept-Language' => 'en'];

    // نقطة جوه التجمع الخامس
    private const LAT = 30.0300;

    private const LNG = 31.4700;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoPlaceSeeder::class);
    }

    public function test_it_returns_collections_and_nearby_in_one_request(): void
    {
        $response = $this->getJson('/api/v1/home?area=new-cairo&lat='.self::LAT.'&lng='.self::LNG, self::AR)
            ->assertOk()
            ->assertJsonStructure(['data' => ['collections', 'nearby']]);

        // المجموعات (A5) بعد الإطلاق: الحقل ثابت في العقد وفاضي
        $this->assertSame([], $response->json('data.collections'));
        $this->assertCount(HomeController::NEARBY_LIMIT, $response->json('data.nearby'));
    }

    public function test_nearby_cards_are_the_places_list_cards_with_a_distance_sorted_nearest_first(): void
    {
        $nearby = $this->getJson('/api/v1/home?area=new-cairo&lat='.self::LAT.'&lng='.self::LNG, self::AR)
            ->assertOk()
            ->json('data.nearby');

        $list = $this->getJson('/api/v1/places?per_page=1', self::AR)->assertOk()->json('data.0');

        $this->assertSame(array_keys($list), array_keys($nearby[0]));

        $distances = array_column($nearby, 'distance_km');
        $this->assertNotContains(null, $distances);
        $sorted = $distances;
        sort($sorted);
        $this->assertSame($sorted, $distances);
    }

    public function test_nearby_is_empty_without_a_position(): void
    {
        $this->getJson('/api/v1/home?area=new-cairo', self::AR)
            ->assertOk()
            ->assertJsonPath('data.nearby', [])
            ->assertJsonPath('data.collections', []);
    }

    public function test_nearby_never_includes_unpublished_places_and_stops_at_thirty_km(): void
    {
        $url = '/api/v1/home?area=new-cairo&lat='.self::LAT.'&lng='.self::LNG;
        $slugs = array_column($this->getJson($url, self::AR)->assertOk()->json('data.nearby'), 'slug');

        $unpublished = DB::table('places')
            ->where(fn ($query) => $query->where('status', '!=', 'active')->orWhereNotNull('deleted_at'))
            ->pluck('slug')
            ->all();

        $this->assertNotEmpty($unpublished);
        $this->assertSame([], array_values(array_intersect($slugs, $unpublished)));

        // الإسكندرية بعيدة عن كل الأماكن (أكتر من 30 كم)
        $this->getJson('/api/v1/home?area=new-cairo&lat=31.2001&lng=29.9187', self::AR)
            ->assertOk()
            ->assertJsonPath('data.nearby', []);
    }

    public function test_names_follow_the_request_language(): void
    {
        $arabic = $this->getJson('/api/v1/home?area=new-cairo&lat='.self::LAT.'&lng='.self::LNG, self::AR)->json('data.nearby.0');
        $english = $this->getJson('/api/v1/home?area=new-cairo&lat='.self::LAT.'&lng='.self::LNG, self::EN)->json('data.nearby.0');

        $this->assertSame($arabic['slug'], $english['slug']);
        $this->assertNotSame($arabic['name'], $english['name']);
    }

    public function test_the_area_is_required_and_must_be_an_active_area(): void
    {
        $this->getJson('/api/v1/home', self::AR)
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR')
            ->assertJsonValidationErrors('area');

        $this->getJson('/api/v1/home?area=atlantis', self::AR)->assertStatus(422)->assertJsonValidationErrors('area');

        // منطقة اتقفلت بعد ما المستخدم اختارها: 422 والموبايل بيطلب اختيار تاني
        DB::table('areas')->where('slug', 'new-cairo')->update(['is_active' => false]);

        $this->getJson('/api/v1/home?area=new-cairo', self::AR)->assertStatus(422)->assertJsonValidationErrors('area');
    }

    public function test_the_position_is_a_pair_inside_the_earth(): void
    {
        $this->getJson('/api/v1/home?area=new-cairo&lat=30.03', self::AR)->assertStatus(422)->assertJsonValidationErrors('lng');
        $this->getJson('/api/v1/home?area=new-cairo&lng=31.47', self::AR)->assertStatus(422)->assertJsonValidationErrors('lat');
        $this->getJson('/api/v1/home?area=new-cairo&lat=91&lng=31', self::AR)->assertStatus(422)->assertJsonValidationErrors('lat');
        $this->getJson('/api/v1/home?area=new-cairo&lat=30&lng=181', self::AR)->assertStatus(422)->assertJsonValidationErrors('lng');
    }

    public function test_it_is_public_and_a_token_changes_nothing(): void
    {
        $url = '/api/v1/home?area=new-cairo&lat='.self::LAT.'&lng='.self::LNG;

        $this->getJson($url, self::AR)->assertOk();
        $this->getJson($url, self::AR + ['Authorization' => 'Bearer 999|not-a-real-token'])->assertOk();
    }
}
