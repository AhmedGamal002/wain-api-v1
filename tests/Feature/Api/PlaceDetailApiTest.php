<?php

namespace Tests\Feature\Api;

use App\Models\Attribute;
use App\Models\Category;
use App\Models\Place;
use Database\Seeders\DemoPlaceSeeder;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * GET /places/{slug} (03 القسم 5.3 و5.4): تفاصيل المكان، وقاعدة الظهور (المنشور والمغلق نهائياً بس)،
 * و404 واحدة `PLACE_NOT_AVAILABLE` لكل الحالات التانية.
 */
class PlaceDetailApiTest extends TestCase
{
    use RefreshDatabase;

    private const AR = ['Accept-Language' => 'ar'];

    private const EN = ['Accept-Language' => 'en'];

    // مكان منشور featured: تواصل كامل وخصائص وصور معرض ومواعيد رمضان
    private const FULL = 'el-mahatta-coffee';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoPlaceSeeder::class);
    }

    private function slugWithStatus(string $status): string
    {
        return (string) DB::table('places')->where('status', $status)->whereNull('deleted_at')->orderBy('id')->value('slug');
    }

    private function placeId(string $slug): int
    {
        return (int) DB::table('places')->where('slug', $slug)->value('id');
    }

    public function test_returns_the_documented_shape_with_the_card_fields(): void
    {
        $json = $this->getJson('/api/v1/places/'.self::FULL, self::AR)->assertOk()->json('data');

        $this->assertEqualsCanonicalizing([
            // الكارت (03 القسم 5.2)
            'id', 'slug', 'name', 'primary_category', 'area', 'cover', 'price', 'rating', 'listing_tier',
            'is_open_now', 'distance_km', 'latitude', 'longitude', 'is_saved',
            // التفاصيل (03 القسم 5.3)
            'status', 'description', 'address', 'categories', 'attributes', 'contact', 'hours', 'photos',
            'menu_photos', 'closed_at', 'verified_at', 'is_claimed', 'share_url', 'latest_reviews',
        ], array_keys($json));

        $this->assertSame(self::FULL, $json['slug']);
        $this->assertSame('قهوة المحطة', $json['name']);
        $this->assertSame('active', $json['status']);
        $this->assertSame('featured', $json['listing_tier']);
        $this->assertSame(['phone', 'whatsapp', 'instagram', 'facebook_url', 'website', 'menu_url'], array_keys($json['contact']));
        $this->assertNotNull($json['contact']['phone']);
        $this->assertSame([], $json['latest_reviews']);
        $this->assertFalse($json['is_claimed']);
        $this->assertNull($json['closed_at']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $json['verified_at']);
        $this->assertSame('https://wain.app/p/'.self::FULL, $json['share_url']);
    }

    public function test_no_internal_field_or_original_path_leaks(): void
    {
        $id = $this->placeId(self::FULL);
        $userId = (int) DB::table('users')->value('id');
        DB::table('places')->where('id', $id)->update([
            'internal_notes' => 'SECRET-INTERNAL-NOTE',
            'status_reason' => 'SECRET-STATUS-REASON',
            'claimed_by' => $userId,
        ]);

        $response = $this->getJson('/api/v1/places/'.self::FULL, self::AR)->assertOk();
        $body = $response->getContent();

        foreach (['internal_notes', 'status_reason', 'created_by', 'updated_by', 'claimed_by', 'search_text', 'search_aliases'] as $field) {
            $this->assertArrayNotHasKey($field, $response->json('data'), "$field must not be in the response");
        }
        $this->assertStringNotContainsString('SECRET-INTERNAL-NOTE', $body);
        $this->assertStringNotContainsString('SECRET-STATUS-REASON', $body);
        // مسار الأصل (path) على R2 ما بيطلعش، والـ variants الجاهزة بس
        $this->assertStringNotContainsString('demo/'.self::FULL, $body);
        // لكن is_claimed بيطلع
        $this->assertTrue($response->json('data.is_claimed'));
    }

    public function test_hidden_draft_pending_rejected_deleted_and_unknown_return_the_same_404(): void
    {
        $slugs = [
            $this->slugWithStatus('hidden'),
            $this->slugWithStatus('draft'),
            $this->slugWithStatus('pending'),
        ];

        // مرفوض
        $rejected = 'bayt-el-bunn';
        DB::table('places')->where('slug', $rejected)->update(['status' => 'rejected']);
        $slugs[] = $rejected;

        // محذوف (soft delete)
        $deleted = 'el-mahatta-coffee';
        DB::table('places')->where('slug', $deleted)->update(['deleted_at' => now()]);
        $slugs[] = $deleted;

        $slugs[] = 'no-such-place';

        $bodies = [];
        foreach ($slugs as $slug) {
            $response = $this->getJson('/api/v1/places/'.$slug, self::AR)
                ->assertNotFound()
                ->assertJsonPath('code', 'PLACE_NOT_AVAILABLE');
            $bodies[] = $response->getContent();
        }

        // نفس الـ body بالظبط لكل الحالات، فمحدش يعرف إن فيه مسودة بالاسم ده
        $this->assertCount(1, array_unique($bodies));
        $this->assertSame('المكان مش متاح دلوقتي.', json_decode($bodies[0], true)['message']);
    }

    public function test_404_message_follows_the_request_language(): void
    {
        $this->getJson('/api/v1/places/no-such-place', self::EN)
            ->assertNotFound()
            ->assertJsonPath('code', 'PLACE_NOT_AVAILABLE')
            ->assertJsonPath('message', 'This place is not available right now.');
    }

    public function test_permanently_closed_place_is_visible_without_open_status(): void
    {
        $slug = $this->slugWithStatus('closed');
        $this->assertNotSame('', $slug);

        $json = $this->getJson('/api/v1/places/'.$slug, self::AR)->assertOk()->json('data');

        $this->assertSame('closed', $json['status']);
        $this->assertNotNull($json['closed_at']);
        $this->assertNull($json['is_open_now']);
        $this->assertSame([
            'is_open_now' => null, 'state' => null, 'status_text' => null, 'is_ramadan' => false, 'week' => [],
        ], $json['hours']);
    }

    public function test_hours_block_agrees_with_the_card_open_flag(): void
    {
        $json = $this->getJson('/api/v1/places/'.self::FULL, self::AR)->assertOk()->json('data');

        $this->assertIsBool($json['is_open_now']);
        $this->assertSame($json['is_open_now'], $json['hours']['is_open_now']);
        $this->assertNotEmpty($json['hours']['status_text']);
        $this->assertCount(7, $json['hours']['week']);
        $this->assertCount(1, array_filter($json['hours']['week'], fn ($day) => $day['is_today']));
    }

    public function test_old_slug_returns_the_place_with_its_new_slug(): void
    {
        DB::table('place_slug_redirects')->insert([
            'old_slug' => 'old-mahatta-name',
            'place_id' => $this->placeId(self::FULL),
        ]);

        $this->getJson('/api/v1/places/old-mahatta-name', self::AR)
            ->assertOk()
            ->assertJsonPath('data.slug', self::FULL);
    }

    public function test_old_slug_of_an_unavailable_place_is_still_404(): void
    {
        DB::table('place_slug_redirects')->insert([
            'old_slug' => 'old-hidden-name',
            'place_id' => $this->placeId($this->slugWithStatus('hidden')),
        ]);

        $this->getJson('/api/v1/places/old-hidden-name', self::AR)
            ->assertNotFound()
            ->assertJsonPath('code', 'PLACE_NOT_AVAILABLE');
    }

    public function test_distance_is_returned_only_when_the_location_is_sent(): void
    {
        $this->getJson('/api/v1/places/'.self::FULL, self::AR)
            ->assertOk()
            ->assertJsonPath('data.distance_km', null);

        // نقطة جوه التجمع الخامس على بعد حوالي 2 كم من المكان
        $km = $this->getJson('/api/v1/places/'.self::FULL.'?lat=30.0300&lng=31.4700', self::AR)
            ->assertOk()
            ->json('data.distance_km');

        $this->assertEqualsWithDelta(2.1, $km, 0.2);
    }

    public function test_location_is_a_pair_and_must_be_in_range(): void
    {
        $this->getJson('/api/v1/places/'.self::FULL.'?lat=30.03', self::AR)
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR')
            ->assertJsonValidationErrors('lng', 'errors');

        $this->getJson('/api/v1/places/'.self::FULL.'?lat=95&lng=31', self::AR)
            ->assertStatus(422)
            ->assertJsonValidationErrors('lat', 'errors');
    }

    public function test_english_request_uses_english_fields_and_falls_back_to_arabic(): void
    {
        $json = $this->getJson('/api/v1/places/'.self::FULL, self::EN)->assertOk()->json('data');

        $this->assertSame('El Mahatta Coffee', $json['name']);
        // المكان التجريبي من غير وصف ولا عنوان بالإنجليزي: بيرجع العربي
        $this->assertSame(DB::table('places')->where('slug', self::FULL)->value('description_ar'), $json['description']);
        $this->assertSame(DB::table('places')->where('slug', self::FULL)->value('address_ar'), $json['address']);
        $this->assertSame('Cafes', $json['primary_category']['name']);

        $groups = array_column($json['attributes'], 'group');
        $this->assertContains('Services', $groups);
        $this->assertNotContains('الخدمات', $groups);
    }

    public function test_english_description_is_used_when_it_exists(): void
    {
        DB::table('places')->where('slug', self::FULL)->update(['description_en' => 'A calm cafe.', 'address_en' => 'Ninetieth St.']);

        $this->getJson('/api/v1/places/'.self::FULL, self::EN)
            ->assertOk()
            ->assertJsonPath('data.description', 'A calm cafe.')
            ->assertJsonPath('data.address', 'Ninetieth St.');

        $this->getJson('/api/v1/places/'.self::FULL, self::AR)
            ->assertOk()
            ->assertJsonPath('data.description', DB::table('places')->where('slug', self::FULL)->value('description_ar'));
    }

    public function test_categories_list_the_primary_first(): void
    {
        $json = $this->getJson('/api/v1/places/'.self::FULL, self::AR)->assertOk()->json('data');

        $this->assertSame(['slug' => 'cafes', 'name' => 'كافيهات'], $json['categories'][0]);
        $this->assertSame(['cafes', 'desserts'], array_column($json['categories'], 'slug'));
        $this->assertSame('cafes', $json['primary_category']['slug']);
    }

    public function test_attributes_are_grouped_in_display_order_and_inactive_ones_are_hidden(): void
    {
        $json = $this->getJson('/api/v1/places/'.self::FULL, self::AR)->assertOk()->json('data');
        $before = collect($json['attributes'])->flatMap(fn ($g) => array_column($g['items'], 'slug'))->all();

        $this->assertEqualsCanonicalizing(['wifi', 'power-outlets', 'quiet', 'study-work'], $before);
        // المجموعات بالترتيب الثابت: الجو، الخدمات، مناسب لـ، المكان (اللي موجود منها بس)
        $order = ['الجو', 'الخدمات', 'مناسب لـ', 'المكان'];
        $positions = array_map(fn ($g) => array_search($g, $order, true), array_column($json['attributes'], 'group'));
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions);
        $this->assertSame(['slug', 'name', 'icon'], array_keys($json['attributes'][0]['items'][0]));

        // بالموديل زي اللوحة (A3): الـ event هو اللي بيخلّي cache التفاصيل stale
        Attribute::query()->where('slug', 'wifi')->firstOrFail()->update(['is_active' => false]);

        $after = $this->getJson('/api/v1/places/'.self::FULL, self::AR)->assertOk()->json('data.attributes');
        $slugs = collect($after)->flatMap(fn ($g) => array_column($g['items'], 'slug'))->all();
        $this->assertNotContains('wifi', $slugs);
    }

    public function test_a_place_without_optional_data_returns_empty_parts(): void
    {
        $id = $this->placeId(self::FULL);
        DB::table('places')->where('id', $id)->update([
            'description_ar' => null,
            'phone' => null, 'whatsapp' => null, 'instagram' => null,
            'opening_hours' => null, 'ramadan_hours' => null,
        ]);
        DB::table('attribute_place')->where('place_id', $id)->delete();
        DB::table('place_hours')->where('place_id', $id)->delete();

        $json = $this->getJson('/api/v1/places/'.self::FULL, self::AR)->assertOk()->json('data');

        $this->assertNull($json['description']);
        $this->assertSame([], $json['attributes']);
        $this->assertSame([
            'phone' => null, 'whatsapp' => null, 'instagram' => null, 'facebook_url' => null, 'website' => null, 'menu_url' => null,
        ], $json['contact']);
        $this->assertNull($json['is_open_now']);
        $this->assertNull($json['hours']['status_text']);
        $this->assertSame([], $json['hours']['week']);
    }

    public function test_gallery_has_the_cover_first_and_only_ready_photos(): void
    {
        $id = $this->placeId(self::FULL);
        $gallery = DB::table('place_photos')->where('place_id', $id)->where('type', 'gallery')->orderBy('sort_order')->pluck('id')->all();
        $this->assertCount(3, $gallery);

        // الغلاف = التاني، وصورة تالتة لسه بتتعالج
        DB::table('places')->where('id', $id)->update(['cover_photo_id' => $gallery[1]]);
        DB::table('place_photos')->where('id', $gallery[2])->update(['status' => 'processing']);

        $photos = $this->getJson('/api/v1/places/'.self::FULL, self::AR)->assertOk()->json('data.photos');

        $this->assertSame([$gallery[1], $gallery[0]], array_column($photos, 'id'));
        $this->assertSame(['id', 'medium', 'large', 'caption'], array_keys($photos[0]));
        $this->assertStringStartsWith('https://', $photos[0]['medium']);
        $this->assertNull($photos[0]['caption']);
    }

    public function test_menu_photos_are_separate_from_the_gallery(): void
    {
        $restaurant = DB::table('places')
            ->join('place_photos', 'place_photos.place_id', '=', 'places.id')
            ->where('place_photos.type', 'menu')
            ->where('places.status', 'active')
            ->orderBy('places.id')
            ->value('places.slug');

        $json = $this->getJson('/api/v1/places/'.$restaurant, self::AR)->assertOk()->json('data');

        $this->assertCount(2, $json['menu_photos']);
        $this->assertNotEmpty($json['photos']);
        $this->assertEmpty(array_intersect(array_column($json['photos'], 'id'), array_column($json['menu_photos'], 'id')));
    }

    public function test_photo_caption_follows_the_request_language(): void
    {
        $photoId = (int) DB::table('place_photos')->where('place_id', $this->placeId(self::FULL))->where('type', 'gallery')->orderBy('sort_order')->value('id');
        DB::table('place_photos')->where('id', $photoId)->update(['caption_ar' => 'الركن الهادي', 'caption_en' => 'The quiet corner']);

        $this->getJson('/api/v1/places/'.self::FULL, self::AR)->assertOk()->assertJsonPath('data.photos.0.caption', 'الركن الهادي');
        $this->getJson('/api/v1/places/'.self::FULL, self::EN)->assertOk()->assertJsonPath('data.photos.0.caption', 'The quiet corner');
    }

    public function test_share_url_uses_the_configured_base(): void
    {
        config(['wain.share_base_url' => 'https://example.test/p']);

        $this->getJson('/api/v1/places/'.self::FULL, self::AR)
            ->assertOk()
            ->assertJsonPath('data.share_url', 'https://example.test/p/'.self::FULL);
    }

    public function test_static_places_routes_are_not_swallowed_by_the_slug_route(): void
    {
        // nearby لسه بيروح للـ endpoint بتاعه (lat وlng إلزاميين فيه)
        $this->getJson('/api/v1/places/nearby', self::AR)
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR');

        // count ليه مسار حقيقي (F5): بيرجّع العدد مش PLACE_NOT_AVAILABLE
        $this->getJson('/api/v1/places/count', self::AR)
            ->assertOk()
            ->assertJsonStructure(['count']);

        // map ليه مسار حقيقي (F6): بيروح للـ endpoint بتاعه (bbox إلزامي فيه) مش PLACE_NOT_AVAILABLE
        $this->getJson('/api/v1/places/map', self::AR)
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR')
            ->assertJsonValidationErrors('bbox');
    }

    // ---- cache التفاصيل (03 القسم 5.5): 5 دقايق، بيتمسح مع أي تغيير ----

    private function placesQueries(callable $request): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $request();
        $queries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'from `places`'));
        DB::disableQueryLog();

        return $queries->count();
    }

    public function test_the_second_request_does_not_query_the_place(): void
    {
        $first = $this->getJson('/api/v1/places/'.self::FULL, self::AR)->assertOk()->json('data');

        $queries = $this->placesQueries(fn () => $this->getJson('/api/v1/places/'.self::FULL, self::AR)->assertOk());

        $this->assertSame(0, $queries);
        $this->assertSame($first, $this->getJson('/api/v1/places/'.self::FULL, self::AR)->json('data'));
    }

    public function test_language_is_applied_after_the_cache(): void
    {
        $ar = $this->getJson('/api/v1/places/'.self::FULL, self::AR)->assertOk()->json('data');
        $en = $this->getJson('/api/v1/places/'.self::FULL, self::EN)->assertOk()->json('data');
        $arAgain = $this->getJson('/api/v1/places/'.self::FULL, self::AR)->assertOk()->json('data');

        $this->assertSame('قهوة المحطة', $ar['name']);
        $this->assertNotSame($ar['name'], $en['name']);
        $this->assertSame($ar, $arAgain);
    }

    public function test_open_status_follows_the_clock_while_the_place_is_cached(): void
    {
        $place = Place::factory()->published()->create(['slug' => 'cache-probe']);
        $this->assertSame('cache-probe', $place->slug);

        // سبت 17 أكتوبر 2026 بتوقيت القاهرة (صيفي): المكان مفتوح 09:00 لـ 23:30 كل يوم
        $this->travelTo(Carbon::parse('2026-10-17 06:00', 'Africa/Cairo'));
        $closed = $this->getJson('/api/v1/places/cache-probe', self::AR)->assertOk()->json('data');

        $this->travelTo(Carbon::parse('2026-10-17 12:00', 'Africa/Cairo'));
        $open = $this->getJson('/api/v1/places/cache-probe', self::AR)->assertOk()->json('data');
        $this->travelBack();

        $this->assertFalse($closed['is_open_now']);
        $this->assertFalse($closed['hours']['is_open_now']);
        $this->assertTrue($open['is_open_now']);
        $this->assertTrue($open['hours']['is_open_now']);
        $this->assertTrue(Cache::has(Place::detailsCacheKey('cache-probe')));
    }

    public function test_distance_is_computed_per_request_from_a_cached_place(): void
    {
        $url = '/api/v1/places/'.self::FULL;
        $this->getJson($url, self::AR)->assertOk()->assertJsonPath('data.distance_km', null);

        $near = $this->getJson($url.'?lat=30.03&lng=31.47', self::AR)->assertOk()->json('data.distance_km');
        $far = $this->getJson($url.'?lat=31.2&lng=29.92', self::AR)->assertOk()->json('data.distance_km');

        $this->assertNotNull($near);
        $this->assertGreaterThan($near + 100, $far);
        $this->getJson($url, self::AR)->assertJsonPath('data.distance_km', null);
    }

    public function test_hiding_a_cached_place_makes_it_404_immediately_and_showing_it_brings_it_back(): void
    {
        $url = '/api/v1/places/'.self::FULL;
        $this->getJson($url, self::AR)->assertOk();

        $place = Place::query()->where('slug', self::FULL)->firstOrFail();
        $place->update(['status' => 'hidden', 'status_reason' => 'بيانات محتاجة مراجعة']);
        $this->getJson($url, self::AR)->assertNotFound()->assertJsonPath('code', 'PLACE_NOT_AVAILABLE');

        $place->update(['status' => 'active']);
        $this->getJson($url, self::AR)->assertOk()->assertJsonPath('data.slug', self::FULL);
    }

    public function test_editing_a_cached_place_shows_the_change_on_the_next_request(): void
    {
        $url = '/api/v1/places/'.self::FULL;
        $this->getJson($url, self::AR)->assertOk()->assertJsonPath('data.name', 'قهوة المحطة');

        Place::query()->where('slug', self::FULL)->firstOrFail()->update(['name_ar' => 'قهوة المحطة الجديدة']);

        $this->getJson($url, self::AR)->assertOk()->assertJsonPath('data.name', 'قهوة المحطة الجديدة');
    }

    public function test_renaming_the_slug_serves_both_links_with_no_stale_cache(): void
    {
        $this->getJson('/api/v1/places/'.self::FULL, self::AR)->assertOk();

        Place::query()->where('slug', self::FULL)->firstOrFail()->update(['slug' => 'el-mahatta-new']);

        $this->getJson('/api/v1/places/el-mahatta-new', self::AR)->assertOk()->assertJsonPath('data.slug', 'el-mahatta-new');
        $this->getJson('/api/v1/places/'.self::FULL, self::AR)->assertOk()->assertJsonPath('data.slug', 'el-mahatta-new');
    }

    public function test_a_catalog_change_is_visible_without_waiting_for_the_cache(): void
    {
        $url = '/api/v1/places/'.self::FULL;
        $before = $this->getJson($url, self::AR)->assertOk()->json('data');
        $category = Category::query()->where('slug', $before['primary_category']['slug'])->firstOrFail();

        $category->update(['name_ar' => 'اسم تصنيف جديد']);

        $this->getJson($url, self::AR)->assertOk()->assertJsonPath('data.primary_category.name', 'اسم تصنيف جديد');
    }

    public function test_unavailable_places_are_not_cached(): void
    {
        $slug = $this->slugWithStatus('hidden');

        $this->getJson('/api/v1/places/'.$slug, self::AR)->assertNotFound();

        $this->assertFalse(Cache::has(Place::detailsCacheKey($slug)));
    }

    public function test_the_cache_never_holds_internal_fields(): void
    {
        Place::query()->where('slug', self::FULL)->firstOrFail()->update(['internal_notes' => 'ملاحظة داخلية']);
        $this->getJson('/api/v1/places/'.self::FULL, self::AR)->assertOk();

        $snapshot = Cache::get(Place::detailsCacheKey(self::FULL));

        $this->assertIsArray($snapshot);
        foreach (['internal_notes', 'search_text', 'location', 'distance_m'] as $field) {
            $this->assertArrayNotHasKey($field, $snapshot['place']);
        }
        $this->assertStringNotContainsString('ملاحظة داخلية', serialize($snapshot));
    }

    public function test_slug_case_does_not_create_a_second_stale_cache_entry(): void
    {
        $this->getJson('/api/v1/places/'.strtoupper(self::FULL), self::AR)->assertOk();
        $this->assertTrue(Cache::has(Place::detailsCacheKey(self::FULL)));

        Place::query()->where('slug', self::FULL)->firstOrFail()->update(['name_ar' => 'اسم متغيّر']);

        $this->getJson('/api/v1/places/'.strtoupper(self::FULL), self::AR)->assertOk()->assertJsonPath('data.name', 'اسم متغيّر');
    }

    public function test_second_request_served_from_a_real_file_cache_works(): void
    {
        $path = storage_path('framework/testing/cache-'.uniqid());
        config(['cache.default' => 'file', 'cache.stores.file.path' => $path]);
        Cache::purge();

        try {
            $url = '/api/v1/places/'.self::FULL;

            $this->getJson($url, self::AR)->assertOk()->assertJsonPath('data.name', 'قهوة المحطة');
            $this->assertTrue(Cache::has(Place::detailsCacheKey(self::FULL)));

            // من الـ file cache (arrays بس، من غير __PHP_Incomplete_Class): بلغة تانية وبموقع
            $this->getJson($url.'?lat=30.03&lng=31.47', self::EN)
                ->assertOk()
                ->assertJsonPath('data.slug', self::FULL)
                ->assertJsonStructure(['data' => ['name', 'primary_category' => ['slug', 'name'], 'attributes', 'photos', 'hours' => ['week']]]);

            Place::query()->where('slug', self::FULL)->firstOrFail()->update(['status' => 'hidden', 'status_reason' => 'شكوى']);
            $this->assertFalse(Cache::has(Place::detailsCacheKey(self::FULL)));
            $this->getJson($url, self::AR)->assertNotFound();
        } finally {
            Cache::flush();
            Cache::purge();
            (new Filesystem)->deleteDirectory($path);
        }
    }
}
