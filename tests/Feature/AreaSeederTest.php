<?php

namespace Tests\Feature;

use App\Filament\Resources\Areas\Schemas\AreaForm;
use Database\Seeders\AreaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AreaSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_the_rest_of_the_areas_inactive_and_keeps_new_cairo_active(): void
    {
        $this->seed(AreaSeeder::class);

        $areas = DB::table('areas')->get()->keyBy('slug');

        $this->assertCount(14, $areas);
        $this->assertTrue((bool) $areas['new-cairo']->is_active);
        $this->assertSame([], $areas->except('new-cairo')->filter(fn ($area) => $area->is_active)->keys()->all());
        $this->assertSame(['cairo', 'giza'], DB::table('cities')->orderBy('sort_order')->pluck('slug')->all());
    }

    public function test_slugs_names_and_centers_are_sound(): void
    {
        $this->seed(AreaSeeder::class);

        foreach (DB::table('areas')->get() as $area) {
            $this->assertMatchesRegularExpression('/^[a-z0-9]+(-[a-z0-9]+)*$/', $area->slug);
            $this->assertNotSame('', $area->name_ar);
            $this->assertNotEmpty(json_decode($area->aliases, true), "{$area->slug} needs aliases for search");
            $this->assertGreaterThanOrEqual(AreaForm::LAT_MIN, $area->center_lat);
            $this->assertLessThanOrEqual(AreaForm::LAT_MAX, $area->center_lat);
            $this->assertGreaterThanOrEqual(AreaForm::LNG_MIN, $area->center_lng);
            $this->assertLessThanOrEqual(AreaForm::LNG_MAX, $area->center_lng);
        }
    }

    public function test_arabic_names_are_unique_inside_each_city(): void
    {
        $this->seed(AreaSeeder::class);

        $duplicates = DB::table('areas')
            ->select('city_id', 'name_ar', DB::raw('count(*) as c'))
            ->groupBy('city_id', 'name_ar')
            ->having('c', '>', 1)
            ->count();

        $this->assertSame(0, $duplicates);
    }

    public function test_rerun_is_idempotent_and_keeps_admin_edits(): void
    {
        $this->seed(AreaSeeder::class);
        DB::table('areas')->where('slug', 'zamalek')->update(['is_active' => true, 'center_lat' => 30.07]);

        $this->seed(AreaSeeder::class);

        $this->assertSame(14, DB::table('areas')->count());
        $zamalek = DB::table('areas')->where('slug', 'zamalek')->first();
        $this->assertTrue((bool) $zamalek->is_active);
        $this->assertEquals(30.07, $zamalek->center_lat);
    }

    public function test_only_new_cairo_shows_in_the_api_until_an_area_is_activated(): void
    {
        $this->seed(AreaSeeder::class);

        $this->getJson('/api/v1/areas')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.slug', 'new-cairo');
    }
}
