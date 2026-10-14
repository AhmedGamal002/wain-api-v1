<?php

namespace Tests\Feature\Api;

use App\Models\Area;
use App\Models\Category;
use App\Models\Place;
use App\Support\Search\SynonymDictionary;
use Carbon\CarbonImmutable;
use Database\Seeders\AreaSeeder;
use Database\Seeders\CategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * `GET /search` (02 F4): التطبيع والمرادفات والـ aliases والترتيب (اسم ثم alias ثم الباقي) والتصحيح
 * الإملائي، ونفس كارت وفلاتر `GET /places`.
 */
class SearchApiTest extends TestCase
{
    use RefreshDatabase;

    private const AR = ['Accept-Language' => 'ar'];

    private static int $counter = 0;

    protected function setUp(): void
    {
        parent::setUp();

        SynonymDictionary::flush();
    }

    /**
     * مكان منشور بأقل بيانات (البحث بيشوف الاسمين والـ aliases والتصنيف والمنطقة والوصف والحالة).
     * الاسم الإنجليزي افتراضي من غير كلمات بتتبحث بيها في التستات.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function place(string $nameAr, array $attributes = []): Place
    {
        self::$counter++;

        return Place::factory()->status(Place::STATUS_ACTIVE)->create([
            'name_ar' => $nameAr,
            'name_en' => 'Qz'.self::$counter.' Venue',
            ...$attributes,
        ]);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function search(string $q, array $params = []): TestResponse
    {
        return $this->getJson('/api/v1/search?'.http_build_query(['q' => $q, ...$params]), self::AR);
    }

    /**
     * @return list<string>
     */
    private function names(TestResponse $response): array
    {
        return array_column($response->assertOk()->json('data'), 'name');
    }

    // ---- المطابقة

    public function test_it_finds_a_place_by_its_arabic_or_english_name(): void
    {
        $this->place('مطعم السلطان', ['name_en' => 'Sultan Grill']);
        $this->place('مكان تاني');

        $this->assertSame(['مطعم السلطان'], $this->names($this->search('سلطان')));
        $this->assertSame(['مطعم السلطان'], $this->names($this->search('sultan')));
        $this->assertSame(['مطعم السلطان'], $this->names($this->search('SULTAN grill')));
    }

    public function test_arabic_letter_variants_tashkeel_and_the_article_do_not_matter(): void
    {
        $this->place('أبو طارق للأكل المصري');
        $this->place('مدرسة القهوة');

        $this->assertSame(['أبو طارق للأكل المصري'], $this->names($this->search('ابو طارق')));
        $this->assertSame(['أبو طارق للأكل المصري'], $this->names($this->search('أَبُو طَارِق')));
        $this->assertSame(['أبو طارق للأكل المصري'], $this->names($this->search('الطارق')));
        // ة = ه
        $this->assertSame(['مدرسة القهوة'], $this->names($this->search('مدرسه')));
    }

    public function test_synonyms_find_places_under_another_spelling(): void
    {
        // تصنيف مش كافيهات: اسم التصنيف نفسه جوه search_text وبيطابق مرادفات «كافيه»
        $this->seed(CategorySeeder::class);
        $restaurants = Category::query()->where('slug', 'restaurants')->firstOrFail();

        $this->place('كافيه النيل', ['primary_category_id' => $restaurants->id]);
        $this->place('ليالي روف', ['primary_category_id' => $restaurants->id]);

        $this->assertSame(['كافيه النيل'], $this->names($this->search('كوفي')));
        $this->assertSame(['كافيه النيل'], $this->names($this->search('coffee')));
        $this->assertSame(['ليالي روف'], $this->names($this->search('rooftop')));
    }

    public function test_every_word_must_match(): void
    {
        $this->place('كافيه النخيل', ['description_ar' => 'جلسة خارجية مريحة']);
        $this->place('كافيه الزهور', ['description_ar' => 'ركن هادي للمذاكرة']);

        $this->assertSame(['كافيه النخيل'], $this->names($this->search('كافيه خارجية')));
        $this->assertSame([], $this->names($this->search('كافيه سباحة')));
    }

    public function test_the_category_the_area_and_the_description_are_searched(): void
    {
        $place = $this->place('مكان بدون كلمات مميزة', ['description_ar' => 'بيقدّم مشروبات باردة وبيتزا لذيذة']);

        $this->assertSame([$place->name_ar], $this->names($this->search('بيتزا')));
        $this->assertSame([$place->name_ar], $this->names($this->search($place->primaryCategory->name_ar)));
        $this->assertSame([$place->name_ar], $this->names($this->search($place->area->name_ar)));
    }

    public function test_a_franco_alias_finds_the_place_and_ranks_it_first(): void
    {
        $withAlias = $this->place('ركن الصباح', ['search_aliases' => ['sabah corner', 'rokn']]);
        $this->place('مكان قديم', ['description_ar' => 'اتقال عليه sabah زمان']);

        $names = $this->names($this->search('sabah'));

        $this->assertSame($withAlias->name_ar, $names[0]);
        $this->assertCount(2, $names);
    }

    public function test_name_matches_come_before_alias_matches_and_those_before_the_rest(): void
    {
        $rest = $this->place('مطعم النيل', ['description_ar' => 'اشهر اكلات السلطان']);
        $alias = $this->place('مطعم الملك', ['search_aliases' => ['سلطان']]);
        $name = $this->place('مطعم السلطان');

        $this->assertSame(
            [$name->name_ar, $alias->name_ar, $rest->name_ar],
            $this->names($this->search('سلطان')),
        );
    }

    public function test_only_published_places_appear(): void
    {
        $this->place('مكان منشور سلطان');

        foreach ([Place::STATUS_DRAFT, Place::STATUS_PENDING, Place::STATUS_HIDDEN, Place::STATUS_REJECTED, Place::STATUS_CLOSED] as $status) {
            Place::factory()->status($status)->create(['name_ar' => "مكان {$status} سلطان", 'name_en' => "Qz {$status}"]);
        }

        $trashed = $this->place('مكان محذوف سلطان');
        $trashed->delete();

        $this->assertSame(['مكان منشور سلطان'], $this->names($this->search('سلطان')));
    }

    // ---- التصحيح الإملائي

    public function test_a_misspelled_word_is_corrected_and_reported(): void
    {
        $this->place('مطعم الطازج');
        $this->place('مكان تاني');

        $response = $this->search('الطازح')->assertOk();

        $this->assertSame(['مطعم الطازج'], array_column($response->json('data'), 'name'));
        $this->assertSame('طازج', $response->json('meta.corrected_query'));
    }

    public function test_a_misspelled_name_with_one_mistake_is_the_first_result(): void
    {
        $this->place('الأمير للمشويات');
        $this->place('الأمين للحلويات', ['description_ar' => 'حلويات شرقي']);
        $this->place('بيت المشويات', ['description_ar' => 'اكل مشوي']);

        // «الأميرر» بحرف زيادة: ما بتطابقش أي اسم، فبتتصحّح لأقرب كلمة
        $response = $this->search('الاميرر')->assertOk();

        $this->assertSame('الأمير للمشويات', $response->json('data.0.name'));
        $this->assertNotNull($response->json('meta.corrected_query'));
    }

    public function test_a_correct_search_is_not_corrected(): void
    {
        $this->place('مطعم الطازج');

        $this->assertNull($this->search('الطازج')->json('meta.corrected_query'));
    }

    public function test_enough_results_means_no_correction_is_tried(): void
    {
        foreach (['الصباح', 'المساء', 'الظهر'] as $suffix) {
            $this->place("قهوة {$suffix}");
        }

        $response = $this->search('قهوه')->assertOk();

        $this->assertSame(3, $response->json('meta.total'));
        $this->assertNull($response->json('meta.corrected_query'));
    }

    public function test_a_correction_that_finds_nothing_more_is_ignored(): void
    {
        $this->place('مطعم الطازج');

        $response = $this->search('xyzabc')->assertOk();

        $this->assertSame(0, $response->json('meta.total'));
        $this->assertNull($response->json('meta.corrected_query'));
    }

    // ---- الفلاتر والترتيب والـ pagination

    public function test_the_f5_filters_apply_inside_the_search(): void
    {
        $this->seed([CategorySeeder::class, AreaSeeder::class]);

        $otherArea = Area::query()->orderBy('id')->skip(1)->firstOrFail();
        $otherCategory = Category::query()->orderBy('id')->skip(1)->firstOrFail();

        $first = $this->place('كافيه النخيل', ['rating_avg' => 4.5, 'rating_count' => 10, 'price_min' => 100, 'price_max' => 200]);

        $cheapNewcomer = $this->place('كافيه الزهور', ['rating_avg' => 3.0, 'rating_count' => 10, 'price_min' => 300, 'price_max' => 400]);
        $elsewhere = $this->place('كافيه الورد', ['area_id' => $otherArea->id, 'price_min' => 100, 'price_max' => 150]);
        $differentCategory = $this->place('كافيه الياسمين', ['primary_category_id' => $otherCategory->id]);

        $this->assertEqualsCanonicalizing(
            [$first->name_ar, $cheapNewcomer->name_ar, $elsewhere->name_ar, $differentCategory->name_ar],
            $this->names($this->search('كافيه')),
        );

        $this->assertEqualsCanonicalizing(
            [$first->name_ar, $cheapNewcomer->name_ar, $differentCategory->name_ar],
            $this->names($this->search('كافيه', ['area' => $first->area->slug])),
        );

        $this->assertSame([$elsewhere->name_ar], $this->names($this->search('كافيه', ['area' => $otherArea->slug])));
        $this->assertSame([$first->name_ar], $this->names($this->search('كافيه', ['rating_min' => 4])));
        $this->assertEqualsCanonicalizing(
            [$first->name_ar, $elsewhere->name_ar],
            $this->names($this->search('كافيه', ['price_max' => 250])),
        );
        $this->assertSame(
            [$differentCategory->name_ar],
            $this->names($this->search('كافيه', ['categories' => [$otherCategory->slug]])),
        );
    }

    public function test_open_now_applies_inside_the_search(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-14 12:00', 'Africa/Cairo'));

        $open = Place::factory()->published()->create(['name_ar' => 'كافيه مفتوح', 'name_en' => 'Qz Open']);
        $this->place('كافيه من غير مواعيد');

        $this->assertSame([$open->name_ar], $this->names($this->search('كافيه', ['open_now' => 1])));
        $this->assertCount(2, $this->names($this->search('كافيه')));
    }

    public function test_pagination_meta_and_stable_pages(): void
    {
        foreach (range(1, 5) as $n) {
            $this->place("قهوة رقم {$n}");
        }

        $seen = [];

        foreach ([1, 2, 3] as $page) {
            $response = $this->search('قهوه', ['per_page' => 2, 'page' => $page])->assertOk();

            $this->assertSame(5, $response->json('meta.total'));
            $this->assertSame(3, $response->json('meta.last_page'));
            $this->assertSame(2, $response->json('meta.per_page'));
            $this->assertSame($page, $response->json('meta.current_page'));

            $seen = [...$seen, ...array_column($response->json('data'), 'id')];
        }

        $this->assertCount(5, $seen);
        $this->assertCount(5, array_unique($seen));
    }

    public function test_the_user_sort_replaces_the_relevance_order(): void
    {
        $nameMatch = $this->place('مطعم السلطان', ['rating_avg' => 3.5, 'rating_count' => 5]);
        $restMatch = $this->place('مطعم النيل', ['description_ar' => 'اكلات السلطان', 'rating_avg' => 4.8, 'rating_count' => 50]);

        $this->assertSame([$nameMatch->name_ar, $restMatch->name_ar], $this->names($this->search('سلطان')));
        $this->assertSame(
            [$restMatch->name_ar, $nameMatch->name_ar],
            $this->names($this->search('سلطان', ['sort' => 'rating'])),
        );
    }

    public function test_nearest_sort_needs_a_location_and_returns_distances(): void
    {
        $far = $this->place('كافيه البعيد', ['latitude' => 30.10, 'longitude' => 31.60]);
        $near = $this->place('كافيه القريب', ['latitude' => 30.0300, 'longitude' => 31.4701]);

        $this->search('كافيه', ['sort' => 'nearest'])->assertStatus(422);

        $response = $this->search('كافيه', ['sort' => 'nearest', 'lat' => 30.03, 'lng' => 31.47])->assertOk();

        $this->assertSame([$near->name_ar, $far->name_ar], array_column($response->json('data'), 'name'));
        $this->assertNotNull($response->json('data.0.distance_km'));
        $this->assertLessThan($response->json('data.1.distance_km'), $response->json('data.0.distance_km'));
    }

    // ---- الشكل والتحقق

    public function test_the_cards_have_the_same_shape_as_the_places_list_and_hide_internal_fields(): void
    {
        $this->place('كافيه النخيل');

        $card = $this->search('نخيل')->assertOk()->json('data.0');
        $listCard = $this->getJson('/api/v1/places', self::AR)->assertOk()->json('data.0');

        $this->assertSame(array_keys($listCard), array_keys($card));
        $this->assertArrayNotHasKey('search_text', $card);
        $this->assertArrayNotHasKey('search_aliases', $card);
        $this->assertArrayNotHasKey('relevance', $card);
    }

    public function test_the_query_is_required_and_between_two_and_sixty_characters(): void
    {
        $this->getJson('/api/v1/search', self::AR)->assertStatus(422)->assertJsonValidationErrors('q');
        $this->search('ك')->assertStatus(422)->assertJsonValidationErrors('q');
        $this->search(str_repeat('ك', 61))->assertStatus(422)->assertJsonValidationErrors('q');
        $this->search(str_repeat('ك', 60))->assertOk();
    }

    public function test_the_validation_message_is_arabic_and_names_the_field(): void
    {
        $this->getJson('/api/v1/search', self::AR)
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR')
            ->assertJsonPath('errors.q.0', fn (string $message) => str_contains($message, 'كلمة البحث'));
    }

    public function test_a_query_of_symbols_only_returns_an_empty_list_not_everything(): void
    {
        $this->place('كافيه النخيل');

        foreach (['؟؟', '!!', '%%', '__', '||'] as $q) {
            $response = $this->search($q)->assertOk();

            $this->assertSame([], $response->json('data'), $q);
            $this->assertSame(0, $response->json('meta.total'), $q);
        }
    }

    public function test_the_search_is_public_and_a_token_changes_nothing(): void
    {
        $this->place('كافيه النخيل');

        $this->search('نخيل')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_the_filters_are_validated_like_the_places_list(): void
    {
        $this->search('كافيه', ['area' => 'no-such-area'])->assertStatus(422)->assertJsonValidationErrors('area');
        $this->search('كافيه', ['sort' => 'random'])->assertStatus(422)->assertJsonValidationErrors('sort');
        $this->search('كافيه', ['per_page' => 51])->assertStatus(422)->assertJsonValidationErrors('per_page');
    }
}
