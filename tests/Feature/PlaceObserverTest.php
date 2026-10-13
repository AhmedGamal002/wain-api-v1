<?php

namespace Tests\Feature;

use App\Events\PlaceChanged;
use App\Models\Area;
use App\Models\Place;
use App\Models\User;
use App\Support\PlaceLocation;
use Database\Factories\PlaceFactory;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * `PlaceObserver`: الحقول المشتقة (slug، location، price_range، tier_rank، published_at)، حفظ الـ slug القديم،
 * و`PlaceChanged` وـ listeners بتاعتنا (مسح cache التفاصيل والمناطق).
 */
class PlaceObserverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleSeeder::class, SettingSeeder::class]);
    }

    private function areaSlug(): string
    {
        return (string) Area::query()->orderBy('id')->value('slug');
    }

    public function test_slug_comes_from_name_en(): void
    {
        $place = Place::factory()->create(['name_en' => 'Cilantro Café & Co.']);

        $this->assertSame('cilantro-cafe-co', $place->slug);
    }

    public function test_a_duplicate_name_gets_the_area_slug_then_a_number(): void
    {
        $first = Place::factory()->create(['name_en' => 'Cilantro']);
        $second = Place::factory()->create(['name_en' => 'Cilantro']);
        $third = Place::factory()->create(['name_en' => 'Cilantro']);
        $fourth = Place::factory()->create(['name_en' => 'Cilantro']);

        $this->assertSame('cilantro', $first->slug);
        $this->assertSame('cilantro-'.$this->areaSlug(), $second->slug);
        $this->assertSame('cilantro-'.$this->areaSlug().'-2', $third->slug);
        $this->assertSame('cilantro-'.$this->areaSlug().'-3', $fourth->slug);
    }

    public function test_reserved_route_words_are_never_used_as_a_slug(): void
    {
        foreach (Place::RESERVED_SLUGS as $word) {
            $place = Place::factory()->create(['name_en' => ucfirst($word)]);

            $this->assertNotSame($word, $place->slug);
            $this->assertSame($word.'-'.$this->areaSlug(), $place->slug);
        }
    }

    public function test_a_soft_deleted_place_still_holds_its_slug(): void
    {
        $first = Place::factory()->create(['name_en' => 'Cilantro']);
        $first->delete();

        $second = Place::factory()->create(['name_en' => 'Cilantro']);

        $this->assertSame('cilantro-'.$this->areaSlug(), $second->slug);
    }

    public function test_an_old_slug_is_not_given_to_a_new_place(): void
    {
        $place = Place::factory()->published()->create(['name_en' => 'Cilantro']);
        $place->update(['slug' => 'cilantro-new']);

        $other = Place::factory()->create(['name_en' => 'Cilantro']);

        $this->assertSame('cilantro-'.$this->areaSlug(), $other->slug);
    }

    public function test_a_name_with_no_latin_letters_falls_back_to_place(): void
    {
        $place = Place::factory()->create(['name_en' => '!!!']);

        $this->assertSame('place', $place->slug);
    }

    public function test_an_explicit_slug_is_kept(): void
    {
        $place = Place::factory()->create(['slug' => 'my-own-slug']);

        $this->assertSame('my-own-slug', $place->slug);
    }

    public function test_location_is_filled_as_lng_lat_and_follows_coordinate_changes(): void
    {
        $place = Place::factory()->create(['latitude' => 30.0444, 'longitude' => 31.2357]);

        $point = DB::table('places')->where('id', $place->id)->selectRaw('ST_X(location) as x, ST_Y(location) as y')->first();
        $this->assertEqualsWithDelta(31.2357, $point->x, 0.00001);
        $this->assertEqualsWithDelta(30.0444, $point->y, 0.00001);

        $place->update(['latitude' => 31.2001, 'longitude' => 29.9187]);

        $point = DB::table('places')->where('id', $place->id)->selectRaw('ST_X(location) as x, ST_Y(location) as y')->first();
        $this->assertEqualsWithDelta(29.9187, $point->x, 0.00001);
        $this->assertEqualsWithDelta(31.2001, $point->y, 0.00001);

        // المسافة المعروفة: القاهرة لـ الإسكندرية ≈ 180 كم (لو المحاور اتبدّلت كانت 183.5)
        $place->update(['latitude' => 30.0444, 'longitude' => 31.2357]);
        $meters = DB::table('places')->where('id', $place->id)
            ->selectRaw(PlaceLocation::distanceMetersSql(31.2001, 29.9187).' as d')->value('d');
        $this->assertEqualsWithDelta(180_000, $meters, 1_500);
    }

    public function test_location_is_not_exposed_as_an_expression_after_saving(): void
    {
        $place = Place::factory()->create();

        $this->assertArrayNotHasKey('location', $place->getAttributes());
        $place->update(['name_ar' => 'اسم جديد']);
        $this->assertArrayNotHasKey('location', $place->getAttributes());
    }

    public function test_price_range_is_computed_from_price_max_and_the_settings_thresholds(): void
    {
        $cases = [100 => 'budget', 249 => 'budget', 250 => 'mid', 600 => 'mid', 601 => 'premium'];
        foreach ($cases as $max => $range) {
            $place = Place::factory()->create(['price_min' => 0, 'price_max' => $max]);
            $this->assertSame($range, $place->price_range, "price_max {$max}");
        }

        DB::table('settings')->where('key', 'price_budget_max')->update(['value' => '100']);
        $place = Place::factory()->create(['price_min' => 0, 'price_max' => 150]);
        $this->assertSame('mid', $place->price_range);

        $place->update(['price_max' => 90]);
        $this->assertSame('budget', $place->fresh()->price_range);

        $place->update(['price_max' => null]);
        $this->assertNull($place->fresh()->price_range);
    }

    public function test_tier_rank_follows_the_listing_tier(): void
    {
        $place = Place::factory()->create();
        $this->assertSame(3, $place->fresh()->tier_rank);

        $place->update(['listing_tier' => 'premium', 'tier_starts_at' => now(), 'tier_ends_at' => now()->addMonth()]);
        $this->assertSame(1, $place->fresh()->tier_rank);

        $place->update(['listing_tier' => 'featured']);
        $this->assertSame(2, $place->fresh()->tier_rank);
    }

    public function test_closing_a_place_returns_the_tier_to_free(): void
    {
        $place = Place::factory()->published()->create([
            'listing_tier' => 'premium', 'tier_starts_at' => now()->subDay(), 'tier_ends_at' => now()->addMonth(),
        ]);

        $place->update(['status' => 'closed', 'closed_at' => now()->toDateString()]);

        $place->refresh();
        $this->assertSame('free', $place->listing_tier);
        $this->assertSame(3, $place->tier_rank);
        $this->assertNull($place->tier_starts_at);
        $this->assertNull($place->tier_ends_at);
    }

    public function test_published_at_is_set_the_first_time_a_place_becomes_active_and_never_reset(): void
    {
        $place = Place::factory()->complete()->create();
        $this->assertNull($place->published_at);

        $this->travelTo(now()->addDay());
        $place->update(['status' => 'active']);
        $firstPublish = $place->fresh()->published_at;
        $this->assertNotNull($firstPublish);

        $this->travelTo(now()->addDays(3));
        $place->update(['status' => 'hidden', 'status_reason' => 'بيانات محتاجة مراجعة']);
        $place->update(['status' => 'active']);

        $this->assertTrue($place->fresh()->published_at->equalTo($firstPublish));
        $this->travelBack();
    }

    public function test_renaming_a_published_slug_keeps_the_old_link_working(): void
    {
        $place = Place::factory()->published()->create(['slug' => 'cilantro']);

        $place->update(['slug' => 'cilantro-new-cairo']);

        $this->assertDatabaseHas('place_slug_redirects', ['old_slug' => 'cilantro', 'place_id' => $place->id]);
        $this->getJson('/api/v1/places/cilantro', ['Accept-Language' => 'ar'])
            ->assertOk()
            ->assertJsonPath('data.slug', 'cilantro-new-cairo');
    }

    public function test_renaming_back_removes_the_redirect(): void
    {
        $place = Place::factory()->published()->create(['slug' => 'cilantro']);
        $place->update(['slug' => 'cilantro-new-cairo']);

        $place->update(['slug' => 'cilantro']);

        $this->assertDatabaseMissing('place_slug_redirects', ['old_slug' => 'cilantro']);
        $this->assertDatabaseHas('place_slug_redirects', ['old_slug' => 'cilantro-new-cairo', 'place_id' => $place->id]);
    }

    public function test_renaming_a_never_published_place_leaves_no_redirect(): void
    {
        $place = Place::factory()->create(['slug' => 'draft-name']);

        $place->update(['slug' => 'better-name']);

        $this->assertSame('better-name', $place->fresh()->slug);
        $this->assertDatabaseCount('place_slug_redirects', 0);
    }

    public function test_a_blank_slug_update_keeps_the_original(): void
    {
        $place = Place::factory()->published()->create(['slug' => 'cilantro']);

        $place->update(['slug' => '']);

        $this->assertSame('cilantro', $place->fresh()->slug);
        $this->assertDatabaseCount('place_slug_redirects', 0);
    }

    public function test_created_by_and_updated_by_follow_the_signed_in_user(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $moderator = User::factory()->create()->assignRole('moderator');

        $this->actingAs($admin);
        $place = Place::factory()->create();
        $this->assertSame($admin->id, $place->created_by);
        $this->assertSame($admin->id, $place->updated_by);

        $this->actingAs($moderator);
        $place->update(['name_ar' => 'اسم معدّل']);
        $this->assertSame($admin->id, $place->fresh()->created_by);
        $this->assertSame($moderator->id, $place->fresh()->updated_by);
    }

    public function test_a_save_without_changes_does_not_change_updated_by(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $moderator = User::factory()->create()->assignRole('moderator');
        $this->actingAs($admin);
        $place = Place::factory()->create();

        $this->actingAs($moderator);
        $place->save();

        $this->assertSame($admin->id, $place->fresh()->updated_by);
    }

    public function test_place_changed_fires_on_save_delete_and_restore(): void
    {
        $place = Place::factory()->create();

        Event::fake([PlaceChanged::class]);

        $place->update(['name_ar' => 'اسم جديد']);
        Event::assertDispatchedTimes(PlaceChanged::class, 1);

        $place->delete();
        Event::assertDispatchedTimes(PlaceChanged::class, 2);

        // الاسترجاع بيحفظ deleted_at = null، فبيعدّي على saved مرة واحدة
        $place->restore();
        Event::assertDispatchedTimes(PlaceChanged::class, 3);
    }

    public function test_place_hours_are_rebuilt_from_the_place_changed_listener(): void
    {
        $place = Place::factory()->complete()->create();

        $this->assertSame(7, DB::table('place_hours')->where('place_id', $place->id)->count());

        $place->update(['opening_hours' => array_replace(
            PlaceFactory::weekHours(),
            ['mon' => ['state' => 'closed']],
        )]);

        $this->assertSame(6, DB::table('place_hours')->where('place_id', $place->id)->count());
    }

    public function test_the_details_cache_is_forgotten_on_any_change(): void
    {
        $place = Place::factory()->published()->create(['slug' => 'cilantro']);
        Cache::put(Place::detailsCacheKey('cilantro'), ['stale' => true], 300);

        $place->update(['name_ar' => 'اسم جديد']);

        $this->assertFalse(Cache::has(Place::detailsCacheKey('cilantro')));
    }

    public function test_renaming_the_slug_forgets_both_the_new_and_the_old_details_cache(): void
    {
        $place = Place::factory()->published()->create(['slug' => 'cilantro']);
        Cache::put(Place::detailsCacheKey('cilantro'), ['stale' => true], 300);
        Cache::put(Place::detailsCacheKey('cilantro-new'), ['stale' => true], 300);

        $place->update(['slug' => 'cilantro-new']);

        $this->assertFalse(Cache::has(Place::detailsCacheKey('cilantro')));
        $this->assertFalse(Cache::has(Place::detailsCacheKey('cilantro-new')));
    }

    public function test_hiding_and_publishing_forget_the_areas_cache(): void
    {
        $place = Place::factory()->published()->create();

        Cache::put(Area::CACHE_KEY, ['stale'], 3600);
        $place->update(['status' => 'hidden', 'status_reason' => 'شكوى']);
        $this->assertFalse(Cache::has(Area::CACHE_KEY));

        Cache::put(Area::CACHE_KEY, ['stale'], 3600);
        $place->update(['status' => 'active']);
        $this->assertFalse(Cache::has(Area::CACHE_KEY));

        Cache::put(Area::CACHE_KEY, ['stale'], 3600);
        $place->delete();
        $this->assertFalse(Cache::has(Area::CACHE_KEY));
    }

    public function test_the_areas_api_count_follows_publish_and_hide(): void
    {
        $place = Place::factory()->published()->create();
        $count = fn () => $this->getJson('/api/v1/areas', ['Accept-Language' => 'ar'])->assertOk()->json('data.0.places_count');

        $this->assertSame(1, $count());

        $place->update(['status' => 'hidden', 'status_reason' => 'شكوى']);
        $this->assertSame(0, $count());

        $place->update(['status' => 'active']);
        $this->assertSame(1, $count());
    }

    public function test_changes_are_logged_with_old_and_new_values_and_who_did_it(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $this->actingAs($admin);
        $place = Place::factory()->create(['name_ar' => 'القديم']);

        $place->update(['name_ar' => 'الجديد']);

        $log = Activity::query()->where('log_name', Place::LOG_NAME)->where('subject_id', $place->id)->latest('id')->first();
        $this->assertSame('updated', $log->event);
        $this->assertSame($admin->id, $log->causer_id);
        $this->assertSame('القديم', $log->properties['old']['name_ar']);
        $this->assertSame('الجديد', $log->properties['attributes']['name_ar']);
        // الحقول المشتقة مش في السجل
        $this->assertArrayNotHasKey('location', $log->properties['attributes']);
        $this->assertArrayNotHasKey('search_text', $log->properties['attributes']);
    }
}
