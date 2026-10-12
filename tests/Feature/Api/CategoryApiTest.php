<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use Database\Seeders\CategorySeeder;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CategoryApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_only_active_categories_in_order_with_the_card_shape(): void
    {
        $this->makeCategory('b-second', 'ثانيها', 'Second', 2);
        $this->makeCategory('a-first', 'أولها', 'First', 1);
        $this->makeCategory('hidden', 'مخفي', 'Hidden', 0, active: false);

        $response = $this->getJson('/api/v1/categories', ['Accept-Language' => 'ar']);

        $response->assertOk()
            ->assertExactJson(['data' => [
                ['slug' => 'a-first', 'name' => 'أولها', 'icon' => '☕', 'color' => '#6F4E37'],
                ['slug' => 'b-second', 'name' => 'ثانيها', 'icon' => '☕', 'color' => '#6F4E37'],
            ]]);
    }

    public function test_name_follows_accept_language_and_defaults_to_arabic(): void
    {
        $this->makeCategory('cafes', 'كافيهات', 'Cafes', 1);

        // Symfony بيحط Accept-Language: en-us في أي request مصطنع، فبنفضّيه عشان نحاكي «من غير هيدر»
        $this->getJson('/api/v1/categories', ['Accept-Language' => ''])->assertJsonPath('data.0.name', 'كافيهات');
        $this->getJson('/api/v1/categories', ['Accept-Language' => 'en'])->assertJsonPath('data.0.name', 'Cafes');
        $this->getJson('/api/v1/categories', ['Accept-Language' => 'en-US,en;q=0.9'])->assertJsonPath('data.0.name', 'Cafes');
        $this->getJson('/api/v1/categories', ['Accept-Language' => 'fr'])->assertJsonPath('data.0.name', 'كافيهات');
    }

    public function test_english_falls_back_to_arabic_when_english_name_is_blank(): void
    {
        $this->makeCategory('cafes', 'كافيهات', '', 1);

        $this->getJson('/api/v1/categories', ['Accept-Language' => 'en'])->assertJsonPath('data.0.name', 'كافيهات');
    }

    public function test_cache_is_cleared_when_a_category_changes(): void
    {
        $category = $this->makeCategory('cafes', 'كافيهات', 'Cafes', 1);

        $this->getJson('/api/v1/categories')->assertJsonCount(1, 'data');
        $this->assertTrue(Cache::has(Category::CACHE_KEY));

        $category->update(['is_active' => false]);

        $this->assertFalse(Cache::has(Category::CACHE_KEY));
        $this->getJson('/api/v1/categories')->assertJsonCount(0, 'data');
    }

    /**
     * الـ array store مابيعملش serialize، فكان بيخبّي bug: `cache.serializable_classes = false`
     * بيمنع unserialize للـ models. التست ده على file store فعلي (زي الاستضافة).
     */
    public function test_second_request_served_from_a_real_file_cache_works(): void
    {
        $path = storage_path('framework/testing/cache-'.uniqid());
        config(['cache.default' => 'file', 'cache.stores.file.path' => $path]);
        Cache::purge();

        try {
            $this->makeCategory('cafes', 'كافيهات', 'Cafes', 1);

            $first = $this->getJson('/api/v1/categories', ['Accept-Language' => 'ar']);
            $second = $this->getJson('/api/v1/categories', ['Accept-Language' => 'en']);

            $first->assertOk()->assertJsonPath('data.0.name', 'كافيهات');
            $second->assertOk()->assertJsonPath('data.0.name', 'Cafes');
            $this->assertTrue(Cache::has(Category::CACHE_KEY));
        } finally {
            Cache::flush();
            Cache::purge();
            (new Filesystem)->deleteDirectory($path);
        }
    }

    public function test_seeder_creates_eight_unique_active_categories_and_is_idempotent(): void
    {
        $this->seed(CategorySeeder::class);
        $this->seed(CategorySeeder::class);

        $this->assertSame(8, Category::query()->count());
        $this->assertSame(8, Category::query()->distinct()->count('slug'));
        $this->assertSame(8, Category::query()->active()->count());
        $this->getJson('/api/v1/categories')->assertJsonCount(8, 'data');
    }

    public function test_seeder_does_not_overwrite_admin_edits(): void
    {
        $this->seed(CategorySeeder::class);
        Category::query()->where('slug', 'cafes')->update(['name_ar' => 'قهوة', 'is_active' => false]);

        $this->seed(CategorySeeder::class);

        $cafes = Category::query()->where('slug', 'cafes')->firstOrFail();
        $this->assertSame('قهوة', $cafes->name_ar);
        $this->assertFalse($cafes->is_active);
    }

    private function makeCategory(string $slug, string $ar, string $en, int $order, bool $active = true): Category
    {
        return Category::query()->create([
            'slug' => $slug,
            'name_ar' => $ar,
            'name_en' => $en,
            'icon' => '☕',
            'color' => '#6F4E37',
            'sort_order' => $order,
            'is_active' => $active,
        ]);
    }
}
