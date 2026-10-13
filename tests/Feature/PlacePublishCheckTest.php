<?php

namespace Tests\Feature;

use App\Models\Place;
use App\Support\PlacePublishCheck;
use Database\Factories\PlaceFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * شروط النشر التسعة (03 القسم 4.3): مكان ناقص كل شرط لوحده بيتمسك، والرسالة بتسمّي الشرط.
 */
class PlacePublishCheckTest extends TestCase
{
    use RefreshDatabase;

    /**
     * لقطة بيانات مكتملة للـ check() من غير داتابيز.
     *
     * @return array<string, mixed>
     */
    private function snapshot(array $overrides = []): array
    {
        return $overrides + [
            'name_ar' => 'مكان',
            'name_en' => 'Place',
            'primary_category_id' => 1,
            'category_ids' => [1, 2],
            'area_id' => 1,
            'latitude' => 30.03,
            'longitude' => 31.47,
            'address_ar' => 'شارع التسعين',
            'description_ar' => str_repeat('و', 60),
            'attribute_count' => 3,
            'price_min' => 100,
            'price_max' => 200,
            'opening_hours' => PlaceFactory::weekHours(),
            'cover_ready' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function week(array $overrides): array
    {
        return $overrides + PlaceFactory::weekHours();
    }

    public function test_a_complete_place_passes_all_nine_conditions(): void
    {
        $result = PlacePublishCheck::for(Place::factory()->complete()->create());

        $this->assertTrue($result->isComplete());
        $this->assertSame(9, $result->passedCount());
        $this->assertSame(9, $result->total());
        $this->assertSame([], $result->missing());
        $this->assertTrue($result->canSaveAsDraft());
    }

    public function test_a_draft_with_only_the_mandatory_fields_passes_the_draft_rules_but_not_the_publish_rules(): void
    {
        $result = PlacePublishCheck::for(Place::factory()->create());

        $this->assertTrue($result->canSaveAsDraft());
        $this->assertFalse($result->isComplete());
        $this->assertEqualsCanonicalizing(
            ['description', 'attributes', 'price', 'hours', 'cover'],
            $result->missing(),
        );
    }

    /**
     * كل شرط لوحده: المكان المكتمل بيتعدّل عشان يكسر شرط واحد بس، والباقي يفضل ✔.
     *
     * @return array<string, array{0: string, 1: callable(Place): void}>
     */
    public static function singleMissingCondition(): array
    {
        return [
            'names' => ['names', fn (Place $p) => $p->update(['name_en' => ''])],
            'primary_category' => ['primary_category', fn (Place $p) => $p->categories()->detach()],
            'location' => ['location', fn (Place $p) => $p->update(['latitude' => 40.0])],
            'address' => ['address', fn (Place $p) => $p->update(['address_ar' => '  '])],
            'description' => ['description', fn (Place $p) => $p->update(['description_ar' => 'قصير'])],
            'attributes' => ['attributes', fn (Place $p) => $p->placeAttributes()->detach($p->placeAttributes()->first())],
            'price' => ['price', fn (Place $p) => $p->update(['price_min' => null, 'price_max' => null])],
            'hours' => ['hours', fn (Place $p) => $p->update(['opening_hours' => null])],
            'cover' => ['cover', fn (Place $p) => $p->coverPhoto->update(['status' => 'processing'])],
        ];
    }

    #[DataProvider('singleMissingCondition')]
    public function test_each_condition_alone_is_caught_and_named(string $key, callable $break): void
    {
        $place = Place::factory()->complete()->create();
        $break($place);

        $result = PlacePublishCheck::for(Place::query()->findOrFail($place->id));

        $this->assertSame([$key], $result->missing());
        $this->assertSame(8, $result->passedCount());
        $this->assertStringContainsString(PlacePublishCheck::LABELS[$key], $result->message());
        $this->assertStringStartsWith('ما ينفعش يتنشر. ناقص: ', $result->message());
    }

    public function test_a_primary_category_outside_the_selected_categories_fails(): void
    {
        $result = PlacePublishCheck::check($this->snapshot(['primary_category_id' => 9, 'category_ids' => [1, 2]]));

        $this->assertSame(['primary_category'], $result->missing());
    }

    public function test_description_length_boundaries_are_50_to_1000_characters(): void
    {
        foreach ([49 => false, 50 => true, 1000 => true, 1001 => false] as $length => $ok) {
            $result = PlacePublishCheck::check($this->snapshot(['description_ar' => str_repeat('م', $length)]));

            $this->assertSame($ok, ! in_array('description', $result->missing(), true), "length {$length}");
        }
    }

    public function test_price_needs_both_values_with_max_not_below_min(): void
    {
        $this->assertTrue(PlacePublishCheck::check($this->snapshot(['price_min' => 0, 'price_max' => 0]))->isComplete());
        $this->assertSame(['price'], PlacePublishCheck::check($this->snapshot(['price_min' => 200, 'price_max' => 100]))->missing());
        $this->assertSame(['price'], PlacePublishCheck::check($this->snapshot(['price_min' => 100, 'price_max' => null]))->missing());
    }

    public function test_location_must_be_inside_egypt_and_have_an_area(): void
    {
        $this->assertSame(['location'], PlacePublishCheck::check($this->snapshot(['area_id' => null]))->missing());
        $this->assertSame(['location'], PlacePublishCheck::check($this->snapshot(['latitude' => 51.5, 'longitude' => -0.12]))->missing());
        $this->assertSame(['location'], PlacePublishCheck::check($this->snapshot(['latitude' => null]))->missing());
    }

    public function test_hours_need_a_valid_state_for_all_seven_days(): void
    {
        $missingDay = PlaceFactory::weekHours();
        unset($missingDay['fri']);

        $this->assertSame(['hours'], PlacePublishCheck::check($this->snapshot(['opening_hours' => $missingDay]))->missing());
        $this->assertSame(['hours'], PlacePublishCheck::check($this->snapshot(['opening_hours' => $this->week(['mon' => ['state' => 'maybe']])]))->missing());
        $this->assertSame(['hours'], PlacePublishCheck::check($this->snapshot(['opening_hours' => []]))->missing());
    }

    public function test_hours_accept_closed_24h_two_slots_and_after_midnight(): void
    {
        $hours = $this->week([
            'mon' => ['state' => 'closed'],
            'tue' => ['state' => '24h'],
            'sun' => ['state' => 'open', 'slots' => [['09:00', '14:00'], ['18:00', '02:00']]],
            'thu' => ['state' => 'open', 'slots' => [['09:00', '01:00']]],
        ]);

        $this->assertTrue(PlacePublishCheck::check($this->snapshot(['opening_hours' => $hours]))->isComplete());
    }

    public function test_hours_reject_overlapping_slots_in_the_same_day(): void
    {
        foreach ([
            [['09:00', '14:00'], ['13:00', '18:00']],
            [['18:00', '02:00'], ['20:00', '23:00']],
            [['09:00', '23:00'], ['10:00', '11:00']],
        ] as $slots) {
            $hours = $this->week(['sun' => ['state' => 'open', 'slots' => $slots]]);

            $this->assertSame(['hours'], PlacePublishCheck::check($this->snapshot(['opening_hours' => $hours]))->missing());
        }
    }

    public function test_hours_reject_an_open_day_with_missing_or_malformed_slots(): void
    {
        foreach ([
            [],
            [['09:00']],
            [['9am', '5pm']],
            [['09:00', '09:00']],
            [['09:00', '25:00']],
            [['09:00', '12:00'], ['13:00', '15:00'], ['16:00', '18:00']],
        ] as $slots) {
            $hours = $this->week(['sun' => ['state' => 'open', 'slots' => $slots]]);

            $this->assertSame(['hours'], PlacePublishCheck::check($this->snapshot(['opening_hours' => $hours]))->missing());
        }
    }

    public function test_missing_tabs_group_the_missing_conditions_by_form_tab(): void
    {
        $result = PlacePublishCheck::check($this->snapshot([
            'name_en' => '', 'description_ar' => '', 'cover_ready' => false, 'attribute_count' => 0,
        ]));

        $this->assertEqualsCanonicalizing(['basic', 'category', 'photos'], $result->missingTabs());
        $this->assertSame(['names'], $result->missingForDraft());
        $this->assertFalse($result->canSaveAsDraft());
    }

    public function test_items_list_all_nine_with_label_and_tab(): void
    {
        $items = PlacePublishCheck::check($this->snapshot(['cover_ready' => false]))->items();

        $this->assertCount(9, $items);
        $this->assertSame(['key' => 'cover', 'label' => 'صورة الغلاف', 'tab' => 'photos', 'ok' => false], $items[8]);
        $this->assertSame(['key' => 'names', 'label' => 'الاسمين بالعربي والإنجليزي', 'tab' => 'basic', 'ok' => true], $items[0]);
    }

    public function test_recommended_items_are_reported_separately_and_never_block(): void
    {
        $place = Place::factory()->complete()->create();

        $recommended = collect(PlacePublishCheck::recommended($place))->pluck('ok', 'key')->all();

        $this->assertSame(['contact' => false, 'gallery' => false, 'description_en' => false, 'menu_url' => false], $recommended);
        $this->assertTrue(PlacePublishCheck::for($place)->isComplete());

        $place->update(['phone' => '+201000000001', 'description_en' => 'A calm place', 'menu_url' => 'https://example.test/menu']);

        $this->assertSame(
            ['contact' => true, 'gallery' => false, 'description_en' => true, 'menu_url' => true],
            collect(PlacePublishCheck::recommended($place->fresh()))->pluck('ok', 'key')->all(),
        );
    }

    public function test_incomplete_ids_lists_only_the_places_missing_a_condition(): void
    {
        $complete = Place::factory()->complete()->create();
        $draft = Place::factory()->create();
        $noCover = Place::factory()->complete()->create();
        $noCover->update(['cover_photo_id' => null]);

        $this->assertEqualsCanonicalizing([$draft->id, $noCover->id], PlacePublishCheck::incompleteIds());
        $this->assertNotContains($complete->id, PlacePublishCheck::incompleteIds());
    }

    public function test_the_list_query_loads_the_check_data_without_n_plus_one(): void
    {
        Place::factory()->complete()->count(5)->create();

        $places = Place::query()->withPublishCheckData()->get();

        DB::enableQueryLog();
        $results = $places->map(fn (Place $p) => PlacePublishCheck::for($p)->isComplete());
        $this->assertSame([], DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertCount(5, $results->filter());
    }
}
