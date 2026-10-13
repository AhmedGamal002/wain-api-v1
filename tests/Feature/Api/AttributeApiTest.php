<?php

namespace Tests\Feature\Api;

use App\Models\Attribute;
use Database\Seeders\AttributeSeeder;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * GET /attributes (A3): الخصائص الفعّالة مجمّعة حسب المجموعة بترتيب العرض.
 */
class AttributeApiTest extends TestCase
{
    use RefreshDatabase;

    private const AR = ['Accept-Language' => 'ar'];

    private const EN = ['Accept-Language' => 'en'];

    private function make(string $slug, string $group, int $order, array $extra = []): Attribute
    {
        return Attribute::query()->create($extra + [
            'slug' => $slug,
            'name_ar' => 'ع '.$slug,
            'name_en' => 'En '.$slug,
            'group' => $group,
            'icon' => '⭐',
            'sort_order' => $order,
        ]);
    }

    public function test_groups_follow_the_display_order_and_items_follow_sort_order(): void
    {
        // متعمولين بترتيب مختلف عن ترتيب العرض
        $this->make('ac', 'facilities', 1);
        $this->make('family', 'suitable_for', 2);
        $this->make('quiet-b', 'vibe', 4);
        $this->make('quiet-a', 'vibe', 3);
        $this->make('wifi', 'services', 5);

        $json = $this->getJson('/api/v1/attributes', self::AR)->assertOk()->json('data');

        $this->assertSame(['vibe', 'services', 'suitable_for', 'facilities'], array_column($json, 'key'));
        $this->assertSame(['الجو', 'الخدمات', 'مناسب لـ', 'المكان'], array_column($json, 'name'));
        $this->assertSame(['quiet-a', 'quiet-b'], array_column($json[0]['items'], 'slug'));
    }

    public function test_item_shape_and_flags(): void
    {
        $this->make('wifi', 'services', 1, ['is_filterable' => true, 'is_quick_filter' => true]);
        $this->make('ac', 'facilities', 2, ['is_filterable' => false]);

        $json = $this->getJson('/api/v1/attributes', self::AR)->assertOk()->json('data');

        $this->assertSame(['slug', 'name', 'icon', 'is_filterable', 'is_quick_filter'], array_keys($json[0]['items'][0]));
        $this->assertSame(['key', 'name', 'items'], array_keys($json[0]));
        $this->assertTrue($json[0]['items'][0]['is_filterable']);
        $this->assertTrue($json[0]['items'][0]['is_quick_filter']);
        // غير الفلاتر لسه بترجع (بتنفع في التقييم)، بس علامتها false
        $this->assertFalse($json[1]['items'][0]['is_filterable']);
        $this->assertFalse($json[1]['items'][0]['is_quick_filter']);
    }

    public function test_hidden_attributes_and_empty_groups_are_not_returned(): void
    {
        $this->make('wifi', 'services', 1);
        $this->make('hidden-one', 'vibe', 2, ['is_active' => false]);

        $json = $this->getJson('/api/v1/attributes', self::AR)->assertOk()->json('data');

        $this->assertSame(['services'], array_column($json, 'key'));
        $this->assertSame(['wifi'], array_column($json[0]['items'], 'slug'));
    }

    public function test_names_follow_accept_language_and_fall_back_to_arabic(): void
    {
        $this->make('wifi', 'services', 1, ['name_ar' => 'واي فاي', 'name_en' => 'Wi-Fi']);
        $this->make('shisha', 'services', 2, ['name_ar' => 'شيشة', 'name_en' => '']);

        $ar = $this->getJson('/api/v1/attributes', self::AR)->json('data.0');
        $en = $this->getJson('/api/v1/attributes', self::EN)->json('data.0');

        $this->assertSame('الخدمات', $ar['name']);
        $this->assertSame(['واي فاي', 'شيشة'], array_column($ar['items'], 'name'));
        $this->assertSame('Services', $en['name']);
        $this->assertSame(['Wi-Fi', 'شيشة'], array_column($en['items'], 'name'));
    }

    public function test_cache_is_cleared_when_an_attribute_changes(): void
    {
        $attribute = $this->make('wifi', 'services', 1);

        $this->getJson('/api/v1/attributes')->assertJsonCount(1, 'data');
        $this->assertTrue(Cache::has(Attribute::CACHE_KEY));

        $attribute->update(['is_active' => false]);

        $this->assertFalse(Cache::has(Attribute::CACHE_KEY));
        $this->getJson('/api/v1/attributes')->assertJsonCount(0, 'data');
    }

    public function test_second_request_served_from_a_real_file_cache_works(): void
    {
        $path = storage_path('framework/testing/cache-'.uniqid());
        config(['cache.default' => 'file', 'cache.stores.file.path' => $path]);
        Cache::purge();

        try {
            $this->make('wifi', 'services', 1, ['name_ar' => 'واي فاي', 'name_en' => 'Wi-Fi']);

            $this->getJson('/api/v1/attributes', self::AR)->assertOk()->assertJsonPath('data.0.items.0.name', 'واي فاي');
            $this->getJson('/api/v1/attributes', self::EN)->assertOk()->assertJsonPath('data.0.items.0.name', 'Wi-Fi');
            $this->assertTrue(Cache::has(Attribute::CACHE_KEY));
        } finally {
            Cache::flush();
            Cache::purge();
            (new Filesystem)->deleteDirectory($path);
        }
    }

    public function test_seeded_list_matches_a3(): void
    {
        $this->seed(AttributeSeeder::class);

        $json = $this->getJson('/api/v1/attributes', self::AR)->assertOk()->json('data');

        $this->assertSame(['vibe', 'services', 'suitable_for', 'facilities'], array_column($json, 'key'));
        $this->assertSame([6, 7, 5, 4], array_map(fn ($group) => count($group['items']), $json));

        $quick = collect($json)->flatMap(fn ($group) => $group['items'])->where('is_quick_filter', true);
        $this->assertCount(Attribute::MAX_QUICK_FILTERS, $quick);
    }
}
