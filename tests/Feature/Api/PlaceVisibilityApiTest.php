<?php

namespace Tests\Feature\Api;

use App\Models\Place;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * معيار قبول الـ API (03 القسم 8): «Feature test لكل حالة: المسودة والمخفي والمرفوض والمحذوف ما بيظهروش في
 * أي endpoint». كل حالة × كل endpoint بيرجّع أماكن (القايمة والعدد والقريب والخريطة والرئيسية والبحث
 * والاقتراحات)، ومعاها `places_count` في `GET /areas`. المغلق نهائياً مستثنى من القوايم وبيفتح بالـ slug بس
 * (`PlaceDetailApiTest` و`PlaceEventApiTest`).
 *
 * كل الأماكن في نفس النقطة والمنطقة وبنفس كلمة البحث، فأي endpoint بيعدّي مكان ما ينفعش بيبان.
 */
class PlaceVisibilityApiTest extends TestCase
{
    use RefreshDatabase;

    private const AR = ['Accept-Language' => 'ar'];

    private const LAT = 30.03;

    private const LNG = 31.47;

    private const WORD = 'ظهور';

    /** الحالات اللي مفروض ما تظهرش في أي قايمة (المحذوف حالة تانية). */
    private const HIDDEN_STATES = [
        Place::STATUS_DRAFT,
        Place::STATUS_PENDING,
        Place::STATUS_HIDDEN,
        Place::STATUS_REJECTED,
        Place::STATUS_CLOSED,
    ];

    private Place $visible;

    /** @var array<string, Place> الحالة ← المكان اللي ما ينفعش يظهر */
    private array $invisible = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->visible = $this->place('منشور', Place::STATUS_ACTIVE);

        foreach (self::HIDDEN_STATES as $status) {
            $this->invisible[$status] = $this->place($status, $status);
        }

        $deleted = $this->place('محذوف', Place::STATUS_ACTIVE);
        $deleted->delete();
        $this->invisible['deleted'] = $deleted;
    }

    private function place(string $label, string $status): Place
    {
        return Place::factory()->status($status)->create([
            'name_ar' => 'مكان '.self::WORD.' '.$label,
            'name_en' => 'Visibility '.$status.' '.$label,
        ]);
    }

    /**
     * @return list<string>
     */
    private function slugs(string $url, string $path = 'data'): array
    {
        return array_column($this->getJson($url, self::AR)->assertOk()->json($path), 'slug');
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function listingEndpoints(): array
    {
        $point = 'lat='.self::LAT.'&lng='.self::LNG;

        return [
            'GET /places' => ['/api/v1/places?area=new-cairo&per_page=50', 'data'],
            'GET /places (nearest)' => ['/api/v1/places?sort=nearest&per_page=50&'.$point, 'data'],
            'GET /places/nearby' => ['/api/v1/places/nearby?radius=5&limit=30&'.$point, 'data'],
            'GET /places/map' => ['/api/v1/places/map?bbox=31.40,29.98,31.55,30.08', 'data'],
            'GET /home' => ['/api/v1/home?area=new-cairo&'.$point, 'data.nearby'],
            'GET /search' => ['/api/v1/search?q='.self::WORD, 'data'],
            'GET /search/suggestions' => ['/api/v1/search/suggestions?q='.self::WORD, 'data'],
        ];
    }

    #[DataProvider('listingEndpoints')]
    public function test_only_the_published_place_is_listed(string $url, string $path): void
    {
        $slugs = $this->slugs($url, $path);

        $this->assertContains($this->visible->slug, $slugs, 'المنشور لازم يظهر (وإلا التست مش بيثبت حاجة)');

        foreach ($this->invisible as $state => $place) {
            $this->assertNotContains($place->slug, $slugs, "مكان «{$state}» ظهر في {$url}");
        }
    }

    public function test_the_count_only_counts_the_published_place(): void
    {
        $this->assertSame(1, $this->getJson('/api/v1/places/count?area=new-cairo', self::AR)->assertOk()->json('count'));
    }

    public function test_the_area_places_count_only_counts_the_published_place(): void
    {
        $area = collect($this->getJson('/api/v1/areas', self::AR)->assertOk()->json('data'))
            ->firstWhere('slug', 'new-cairo');

        $this->assertSame(1, $area['places_count']);
    }

    public function test_every_hidden_state_gets_the_same_404_on_the_detail_page(): void
    {
        foreach ($this->invisible as $state => $place) {
            // المغلق نهائياً بيفتح بالـ slug (بس مش في القوايم)، وكل الباقي 404 واحدة
            if ($state === Place::STATUS_CLOSED) {
                $this->getJson('/api/v1/places/'.$place->slug, self::AR)->assertOk();

                continue;
            }

            $this->getJson('/api/v1/places/'.$place->slug, self::AR)
                ->assertNotFound()
                ->assertJsonPath('code', 'PLACE_NOT_AVAILABLE');
        }
    }
}
