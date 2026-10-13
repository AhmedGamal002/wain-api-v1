<?php

namespace Tests\Feature\Api;

use Database\Seeders\DemoPlaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * فلاتر F5 على `GET /places` و`GET /places/count` (02 F5، 03 القسم 5.1): الخصائص AND، السعر بالتقاطع،
 * التقييم، والعدد بيطابق القائمة. كل فلتر بيتقارن بحساب مستقل من الداتابيز (مش من نفس الاستعلام).
 */
class PlaceFiltersApiTest extends TestCase
{
    use RefreshDatabase;

    private const AR = ['Accept-Language' => 'ar'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoPlaceSeeder::class);
    }

    /**
     * @return Collection<int, object>
     */
    private function published(): Collection
    {
        return DB::table('places')->where('status', 'active')->whereNull('deleted_at')->get();
    }

    /**
     * @param  array<string, mixed>  $params
     * @return list<int>
     */
    private function listIds(array $params): array
    {
        $ids = [];
        $page = 1;

        do {
            $json = $this->getJson('/api/v1/places?'.http_build_query([...$params, 'per_page' => 50, 'page' => $page]), self::AR)
                ->assertOk()
                ->json();
            $ids = array_merge($ids, array_column($json['data'], 'id'));
            $page++;
        } while ($page <= $json['meta']['last_page']);

        sort($ids);

        return $ids;
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function countFor(array $params = []): int
    {
        return $this->getJson('/api/v1/places/count?'.http_build_query($params), self::AR)
            ->assertOk()
            ->json('count');
    }

    /**
     * ids الأماكن المنشورة اللي عندها كل الخصائص دي (حساب مستقل).
     *
     * @param  list<string>  $slugs
     * @return list<int>
     */
    private function expectedWithAttributes(array $slugs): array
    {
        $attributeIds = DB::table('attributes')->whereIn('slug', $slugs)->pluck('id')->all();

        return $this->published()
            ->filter(function ($place) use ($attributeIds) {
                $has = DB::table('attribute_place')->where('place_id', $place->id)->pluck('attribute_id')->all();

                return count(array_intersect($attributeIds, $has)) === count($attributeIds);
            })
            ->pluck('id')->sort()->values()->all();
    }

    public function test_attributes_are_anded_not_ored(): void
    {
        // خاصيتين على الأقل واحدة منهم متفرّقة: لازم AND يطلّع أقل من أي واحدة لوحدها
        $wifi = $this->expectedWithAttributes(['wifi']);
        $quiet = $this->expectedWithAttributes(['quiet']);
        $both = $this->expectedWithAttributes(['wifi', 'quiet']);

        $this->assertNotEmpty($both, 'the demo data must have a place with both');
        $this->assertLessThan(count($wifi), count($both), 'AND must be stricter than one attribute');
        $this->assertLessThan(count($quiet), count($both));

        $this->assertSame($wifi, $this->listIds(['attributes' => ['wifi']]));
        $this->assertSame($both, $this->listIds(['attributes' => ['wifi', 'quiet']]));
    }

    public function test_three_attributes_and_repeated_slug(): void
    {
        $expected = $this->expectedWithAttributes(['wifi', 'quiet', 'card-payment']);

        $this->assertSame($expected, $this->listIds(['attributes' => ['wifi', 'quiet', 'card-payment']]));
        // نفس الخاصية مرتين ما تبوّظش العدّ
        $this->assertSame(
            $this->expectedWithAttributes(['wifi']),
            $this->listIds(['attributes' => ['wifi', 'wifi']]),
        );
    }

    public function test_attributes_that_nothing_has_in_common_return_nothing(): void
    {
        $both = $this->expectedWithAttributes(['lively', 'accessible']);

        $this->assertSame($both, $this->listIds(['attributes' => ['lively', 'accessible']]));
        $this->assertSame(count($both), $this->countFor(['attributes' => ['lively', 'accessible']]));
    }

    public function test_unknown_and_non_filterable_attributes_are_rejected(): void
    {
        $this->getJson('/api/v1/places?attributes[]=nope', self::AR)
            ->assertStatus(422)->assertJsonValidationErrors('attributes.0');

        DB::table('attributes')->where('slug', 'shisha')->update(['is_filterable' => false]);
        $this->getJson('/api/v1/places?attributes[]=shisha', self::AR)
            ->assertStatus(422)->assertJsonValidationErrors('attributes.0');

        DB::table('attributes')->where('slug', 'wifi')->update(['is_active' => false]);
        $this->getJson('/api/v1/places?attributes[]=wifi', self::AR)
            ->assertStatus(422)->assertJsonValidationErrors('attributes.0');
    }

    public function test_too_many_attributes_are_rejected(): void
    {
        $slugs = DB::table('attributes')->limit(11)->pluck('slug')->all();

        $this->getJson('/api/v1/places?'.http_build_query(['attributes' => $slugs]), self::AR)
            ->assertStatus(422)->assertJsonValidationErrors('attributes');
    }

    /**
     * @return array<string, array{0: ?int, 1: ?int}>
     */
    public static function priceRanges(): array
    {
        return [
            'cheap only' => [0, 100],
            'low to mid' => [100, 250],
            'mid' => [250, 600],
            'only a minimum' => [600, null],
            'only a maximum' => [null, 50],
            'one exact price' => [200, 200],
        ];
    }

    #[DataProvider('priceRanges')]
    public function test_price_range_matches_places_whose_range_intersects(?int $from, ?int $to): void
    {
        $expected = $this->published()
            ->filter(fn ($p) => ($from === null || $p->price_max >= $from) && ($to === null || $p->price_min <= $to))
            ->pluck('id')->sort()->values()->all();

        $params = array_filter(['price_min' => $from, 'price_max' => $to], fn ($v) => $v !== null);

        $this->assertSame($expected, $this->listIds($params));
        $this->assertSame(count($expected), $this->countFor($params));
    }

    public function test_price_range_must_be_ordered_and_not_negative(): void
    {
        $this->getJson('/api/v1/places?price_min=300&price_max=100', self::AR)
            ->assertStatus(422)
            ->assertJsonValidationErrors('price_max')
            ->assertJsonPath('errors.price_max.0', 'أعلى سعر لازم يكون 300 أو أكتر.');

        $this->getJson('/api/v1/places?price_min=-5', self::AR)
            ->assertStatus(422)->assertJsonValidationErrors('price_min');

        // أعلى سعر لوحده من غير أقل سعر مقبول (gte مشروطة)
        $this->getJson('/api/v1/places?price_max=100', self::AR)->assertOk();
    }

    public function test_rating_min_only_counts_places_that_show_a_rating(): void
    {
        foreach ([3, 4, 4.5] as $min) {
            $expected = $this->published()
                ->filter(fn ($p) => (float) $p->rating_avg >= $min && $p->rating_count >= 3)
                ->pluck('id')->sort()->values()->all();

            $this->assertNotEmpty($expected, "rating {$min}+ should match something in the demo data");
            $this->assertSame($expected, $this->listIds(['rating_min' => $min]), "rating {$min}+");
        }

        // مكان تقييمه عالي بس تحت 3 تقييمات بيظهر «جديد»، فما يدخلش في «4+»
        $young = $this->published()->first(fn ($p) => (float) $p->rating_avg >= 4 && $p->rating_count > 0 && $p->rating_count < 3);
        if ($young !== null) {
            $this->assertNotContains($young->id, $this->listIds(['rating_min' => 4]));
        }
    }

    public function test_rating_min_is_validated(): void
    {
        $this->getJson('/api/v1/places?rating_min=0', self::AR)->assertStatus(422)->assertJsonValidationErrors('rating_min');
        $this->getJson('/api/v1/places?rating_min=6', self::AR)->assertStatus(422)->assertJsonValidationErrors('rating_min');
        $this->getJson('/api/v1/places?rating_min=abc', self::AR)->assertStatus(422)->assertJsonValidationErrors('rating_min');
    }

    public function test_filters_combine_with_and_between_different_filters(): void
    {
        $categoryId = DB::table('categories')->where('slug', 'cafes')->value('id');
        $inCategory = DB::table('place_category')->where('category_id', $categoryId)->pluck('place_id')->all();

        $expected = $this->published()
            ->filter(fn ($p) => in_array($p->id, $inCategory, true))
            ->filter(fn ($p) => $p->price_max >= 50 && $p->price_min <= 200)
            ->filter(fn ($p) => (float) $p->rating_avg >= 4 && $p->rating_count >= 3)
            ->filter(fn ($p) => in_array($p->id, $this->expectedWithAttributes(['wifi']), true))
            ->pluck('id')->sort()->values()->all();

        $params = [
            'area' => 'new-cairo',
            'categories' => ['cafes'],
            'attributes' => ['wifi'],
            'price_min' => 50,
            'price_max' => 200,
            'rating_min' => 4,
        ];

        $this->assertNotEmpty($expected, 'the combination must match at least one demo place');
        $this->assertSame($expected, $this->listIds($params));
        $this->assertSame(count($expected), $this->countFor($params));
    }

    public function test_filters_never_expose_unpublished_places(): void
    {
        $unpublished = DB::table('places')->where('status', '!=', 'active')->pluck('id')->all();
        $this->assertNotEmpty($unpublished);

        $this->assertSame([], array_intersect($unpublished, $this->listIds(['price_min' => 0])));
        $this->assertSame([], array_intersect($unpublished, $this->listIds(['rating_min' => 1])));
    }

    public function test_count_matches_the_list_total_for_the_same_filters(): void
    {
        foreach ([
            [],
            ['categories' => ['rooftops']],
            ['attributes' => ['family']],
            ['attributes' => ['wifi', 'quiet']],
            ['price_max' => 150],
            ['rating_min' => 4.5],
            ['categories' => ['cafes', 'restaurants'], 'attributes' => ['card-payment'], 'price_min' => 100],
        ] as $params) {
            $total = $this->getJson('/api/v1/places?'.http_build_query([...$params, 'per_page' => 1]), self::AR)
                ->assertOk()->json('meta.total');

            $this->assertSame($total, $this->countFor($params), json_encode($params));
        }
    }

    public function test_count_has_the_documented_shape_and_is_not_swallowed_by_the_slug_route(): void
    {
        $this->getJson('/api/v1/places/count', self::AR)
            ->assertOk()
            ->assertExactJson(['count' => $this->published()->count()]);
    }

    public function test_count_ignores_sort_location_and_page(): void
    {
        $without = $this->countFor(['attributes' => ['family']]);

        $this->assertSame($without, $this->countFor([
            'attributes' => ['family'],
            'sort' => 'nearest',
            'lat' => 30.03,
            'lng' => 31.47,
            'page' => 3,
            'per_page' => 5,
        ]));
    }

    public function test_count_validates_the_same_filters_in_the_request_language(): void
    {
        $this->getJson('/api/v1/places/count?attributes[]=nope&price_min=300&price_max=100', self::AR)
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR')
            ->assertJsonValidationErrors(['attributes.0', 'price_max']);
    }

    public function test_count_follows_open_now(): void
    {
        // فلتر open_now في العدد بيطابق القائمة بالظبط لنفس اللحظة
        $this->assertSame(
            count($this->listIds(['open_now' => 1])),
            $this->countFor(['open_now' => 1]),
        );
    }
}
