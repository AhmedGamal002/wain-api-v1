<?php

namespace Tests\Feature\Api;

use Database\Seeders\DemoPlaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        DB::table('attributes')->where('slug', 'wifi')->update(['is_active' => false]);

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

        // كلمة محجوزة مش slug: مفيش مسار بيطابقها
        $this->getJson('/api/v1/places/count', self::AR)
            ->assertNotFound()
            ->assertJsonPath('code', 'NOT_FOUND');
    }
}
