<?php

namespace Tests\Feature;

use App\Events\PlaceChanged;
use App\Models\Attribute;
use App\Models\Category;
use App\Models\Place;
use App\Support\Search\PlaceSearchText;
use App\Support\Search\SearchNormalizer;
use App\Support\Search\SearchVocabulary;
use Database\Seeders\AttributeSeeder;
use Database\Seeders\DemoPlaceSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * عمود `places.search_text` (02 F4): «الاسمين | الـ aliases | الباقي» مطبَّعة، بيتبني من listener على
 * `PlaceChanged` ومن `places:rebuild-search`.
 */
class PlaceSearchTextTest extends TestCase
{
    use RefreshDatabase;

    private function searchText(Place|int $place): ?string
    {
        return DB::table('places')->where('id', $place instanceof Place ? $place->id : $place)->value('search_text');
    }

    public function test_saving_a_place_builds_the_three_segments(): void
    {
        $place = Place::factory()->create([
            'name_ar' => 'قَهْوة الأصدقاء',
            'name_en' => 'Friends Coffee',
            'search_aliases' => ['Friends Cafe', 'اصحاب'],
            'description_ar' => 'مكان هادئ للقاءات الأصدقاء',
        ]);

        $segments = explode('|', (string) $this->searchText($place));

        $this->assertCount(3, $segments);
        [$names, $aliases, $rest] = $segments;

        $this->assertSame('قهوه الاصدقاء friends coffee', $names);
        $this->assertSame('friends cafe اصحاب', $aliases);
        $this->assertStringContainsString(SearchNormalizer::normalize('مكان هادئ للقاءات الأصدقاء'), $rest);
        $this->assertStringContainsString(SearchNormalizer::normalize($place->primaryCategory->name_ar), $rest);
        $this->assertStringContainsString(SearchNormalizer::normalize($place->area->name_ar), $rest);
    }

    public function test_a_place_without_aliases_has_an_empty_second_segment(): void
    {
        $place = Place::factory()->create(['search_aliases' => null]);

        $this->assertSame('', explode('|', (string) $this->searchText($place))[1]);
    }

    public function test_the_separator_cannot_appear_inside_a_segment(): void
    {
        $place = Place::factory()->create(['name_ar' => 'قهوة | الأصدقاء', 'name_en' => 'A|B']);

        $this->assertSame(2, substr_count((string) $this->searchText($place), '|'));
    }

    public function test_updating_the_place_rebuilds_the_text(): void
    {
        $place = Place::factory()->create(['name_ar' => 'الاسم القديم']);

        $place->update(['name_ar' => 'الاسم الجديد']);

        $this->assertStringContainsString('الجديد', (string) $this->searchText($place));
        $this->assertStringNotContainsString('القديم', (string) $this->searchText($place));
    }

    public function test_visible_attributes_are_included_and_hidden_ones_are_not(): void
    {
        $this->seed(AttributeSeeder::class);
        [$visible, $hidden] = Attribute::query()->orderBy('id')->limit(2)->get();

        DB::table('attributes')->where('id', $visible->id)->update(['name_ar' => 'خاصية ظاهرة', 'name_en' => 'Shown Trait']);
        DB::table('attributes')->where('id', $hidden->id)->update(['name_ar' => 'خاصية مخفية', 'name_en' => 'Hidden Trait', 'is_active' => false]);

        $place = Place::factory()->create();
        $place->placeAttributes()->sync([$visible->id, $hidden->id]);

        // الـ form بيطلق الـ event تاني بعد ما يحفظ الخصائص
        PlaceChanged::dispatch($place->refresh());

        $text = (string) $this->searchText($place);

        $this->assertStringContainsString('خاصيه ظاهره', $text);
        $this->assertStringContainsString('shown trait', $text);
        $this->assertStringNotContainsString('مخفيه', $text);
        $this->assertStringNotContainsString('hidden trait', $text);
    }

    public function test_the_area_aliases_are_searchable(): void
    {
        $place = Place::factory()->create();
        DB::table('areas')->where('id', $place->area_id)->update(['aliases' => json_encode(['فيفث', 'Fifth Settlement'])]);

        PlaceSearchText::rebuild([$place->id]);

        $rest = explode('|', (string) $this->searchText($place))[2];
        $this->assertStringContainsString('فيفث', $rest);
        $this->assertStringContainsString('fifth settlement', $rest);
    }

    public function test_a_trashed_place_is_left_alone_and_a_restored_one_is_rebuilt(): void
    {
        $place = Place::factory()->create(['name_ar' => 'مكان مؤقت']);

        $place->delete();
        DB::table('places')->where('id', $place->id)->update(['search_text' => 'stale||']);
        PlaceChanged::dispatch($place->refresh());

        $this->assertSame('stale||', $this->searchText($place));

        $place->restore();

        $this->assertStringContainsString('مؤقت', (string) $this->searchText($place));
    }

    public function test_the_rebuild_command_handles_one_place_or_all_of_them(): void
    {
        $a = Place::factory()->create();
        $b = Place::factory()->create();
        DB::table('places')->update(['search_text' => null]);

        $this->artisan('places:rebuild-search', ['--place' => $a->id])->assertSuccessful();

        $this->assertNotNull($this->searchText($a));
        $this->assertNull($this->searchText($b));

        $this->artisan('places:rebuild-search')->assertSuccessful();

        $this->assertNotNull($this->searchText($b));
    }

    public function test_many_places_are_written_with_one_statement(): void
    {
        $ids = Place::factory()->count(4)->create()->pluck('id')->all();

        DB::enableQueryLog();
        $updated = PlaceSearchText::rebuild($ids);
        $updates = collect(DB::getQueryLog())->filter(fn (array $q) => str_starts_with($q['query'], 'UPDATE places SET search_text'));
        DB::disableQueryLog();

        $this->assertSame(4, $updated);
        $this->assertCount(1, $updates);
    }

    public function test_rebuilding_an_unknown_place_is_harmless(): void
    {
        $this->assertSame(0, PlaceSearchText::rebuild([999999]));
        $this->assertSame(0, PlaceSearchText::rebuild([]));
    }

    public function test_the_demo_seeder_builds_the_search_text(): void
    {
        $this->seed(DemoPlaceSeeder::class);

        $this->assertSame(0, DB::table('places')->whereNull('search_text')->count());

        $aliases = explode('|', (string) DB::table('places')->where('slug', 'el-mahatta-coffee')->value('search_text'))[1];
        $this->assertStringContainsString('mahatta', $aliases);
    }

    public function test_the_nightly_rebuild_is_scheduled(): void
    {
        $commands = collect(app(Schedule::class)->events())->pluck('command')->implode(' ');

        $this->assertStringContainsString('places:rebuild-search', $commands);
    }

    public function test_a_place_change_forgets_the_spelling_vocabulary(): void
    {
        Cache::put(SearchVocabulary::CACHE_KEY, ['stale' => 1], 60);

        Place::factory()->create();

        $this->assertFalse(Cache::has(SearchVocabulary::CACHE_KEY));
    }

    public function test_the_vocabulary_has_published_places_words_without_the_article(): void
    {
        Place::factory()->status('active')->create(['name_ar' => 'قهوة المحطة', 'name_en' => 'Station Coffee']);
        Place::factory()->status('hidden')->create(['name_ar' => 'مكان مخفي سري', 'name_en' => 'Secret Spot']);

        $words = SearchVocabulary::words();

        $this->assertArrayHasKey('محطه', $words);
        $this->assertArrayHasKey('station', $words);
        // المخفي مش في القاموس
        $this->assertArrayNotHasKey('سري', $words);
        $this->assertArrayNotHasKey('secret', $words);
    }

    // ---- تغيير في المرجعيات (التصنيف والخاصية والمنطقة): الأماكن اللي تحتها بتتحدّث في نفس الحفظ

    private function restOf(Place $place): string
    {
        return explode('|', (string) $this->searchText($place))[2];
    }

    public function test_renaming_a_category_updates_its_places_at_once_and_only_them(): void
    {
        $inside = Place::factory()->status('active')->create(['name_ar' => 'مكان جوه التصنيف']);
        $category = $inside->primaryCategory;

        // مكان تاني التصنيف ده إضافي عنده (مش رئيسي)، ومكان تالت في تصنيف مختلف خالص
        $other = Category::query()->whereKeyNot($category->id)->firstOrFail();
        $extra = Place::factory()->create(['name_ar' => 'مكان تصنيفه إضافي', 'primary_category_id' => $other->id]);
        $extra->categories()->syncWithoutDetaching([$category->id]);
        PlaceChanged::dispatch($extra->refresh());

        $outside = Place::factory()->create(['name_ar' => 'مكان بره', 'primary_category_id' => $other->id]);
        $before = $this->searchText($outside);

        $category->update(['name_ar' => 'تصنيف باسم جديد', 'name_en' => 'Brand New Kind']);

        $this->assertStringContainsString('تصنيف باسم جديد', $this->restOf($inside));
        $this->assertStringContainsString('brand new kind', $this->restOf($inside));
        $this->assertStringContainsString('تصنيف باسم جديد', $this->restOf($extra));
        $this->assertSame($before, $this->searchText($outside));

        // والبحث بالاسم الجديد بيلاقيه على طول
        $names = $this->getJson('/api/v1/search?q='.urlencode('تصنيف باسم جديد'), ['Accept-Language' => 'ar'])
            ->assertOk()
            ->json('data.*.name');

        $this->assertSame(['مكان جوه التصنيف'], $names);
    }

    public function test_renaming_or_hiding_an_attribute_updates_its_places(): void
    {
        $this->seed(AttributeSeeder::class);
        $attribute = Attribute::query()->orderBy('id')->firstOrFail();

        $place = Place::factory()->create();
        $place->placeAttributes()->sync([$attribute->id]);
        PlaceChanged::dispatch($place->refresh());

        $attribute->update(['name_ar' => 'خاصية باسم جديد']);
        $this->assertStringContainsString('خاصيه باسم جديد', $this->restOf($place));

        $attribute->update(['is_active' => false]);
        $this->assertStringNotContainsString('خاصيه باسم جديد', $this->restOf($place));

        $attribute->update(['is_active' => true]);
        $this->assertStringContainsString('خاصيه باسم جديد', $this->restOf($place));
    }

    public function test_changing_the_area_names_updates_its_places(): void
    {
        $place = Place::factory()->create();

        $place->area->update(['name_ar' => 'منطقة باسم جديد', 'aliases' => ['اسم تاني للمنطقة', 'Another Area Name']]);

        $rest = $this->restOf($place);
        $this->assertStringContainsString('منطقه باسم جديد', $rest);
        $this->assertStringContainsString('اسم تاني للمنطقه', $rest);
        $this->assertStringContainsString('another area name', $rest);
    }

    public function test_a_catalog_change_that_is_not_in_the_text_rebuilds_nothing(): void
    {
        $place = Place::factory()->create();
        $category = $place->primaryCategory;

        DB::enableQueryLog();
        $category->update(['sort_order' => 77, 'icon' => '🍵', 'color' => '#112233']);
        $category->update(['is_active' => false]);
        Category::query()->create(['name_ar' => 'تصنيف جديد خالص', 'name_en' => 'Fresh Kind', 'icon' => '🍵', 'color' => '#445566']);
        $writes = collect(DB::getQueryLog())->filter(fn (array $q) => str_starts_with($q['query'], 'UPDATE places SET search_text'));
        DB::disableQueryLog();

        $this->assertCount(0, $writes);
    }

    public function test_a_catalog_change_forgets_the_spelling_vocabulary(): void
    {
        $place = Place::factory()->create();
        Cache::put(SearchVocabulary::CACHE_KEY, ['stale' => 1], 60);

        $place->primaryCategory->update(['name_ar' => 'تصنيف تاني']);

        $this->assertFalse(Cache::has(SearchVocabulary::CACHE_KEY));
    }
}
