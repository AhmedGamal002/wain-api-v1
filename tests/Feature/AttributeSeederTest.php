<?php

namespace Tests\Feature;

use Database\Seeders\AttributeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AttributeSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_the_initial_list_grouped_as_in_a3(): void
    {
        $this->seed(AttributeSeeder::class);

        $perGroup = DB::table('attributes')->select('group', DB::raw('count(*) as c'))->groupBy('group')->pluck('c', 'group');

        $this->assertEquals(['vibe' => 6, 'services' => 7, 'suitable_for' => 5, 'facilities' => 4], $perGroup->all());
        $this->assertSame(22, DB::table('attributes')->distinct()->count('slug'));
    }

    public function test_at_most_six_quick_filters_and_wifi_quiet_are_included(): void
    {
        $this->seed(AttributeSeeder::class);

        $quick = DB::table('attributes')->where('is_quick_filter', true)->pluck('slug')->all();

        $this->assertCount(6, $quick);
        $this->assertContains('wifi', $quick);
        $this->assertContains('quiet', $quick);
    }

    public function test_slugs_are_url_safe_and_names_fit_the_column_limits(): void
    {
        $this->seed(AttributeSeeder::class);

        foreach (DB::table('attributes')->get() as $attribute) {
            $this->assertMatchesRegularExpression('/^[a-z0-9]+(-[a-z0-9]+)*$/', $attribute->slug);
            $this->assertLessThanOrEqual(30, mb_strlen($attribute->name_ar), $attribute->slug);
            $this->assertLessThanOrEqual(30, mb_strlen($attribute->name_en), $attribute->slug);
        }
    }

    public function test_rerun_does_not_overwrite_admin_edits(): void
    {
        $this->seed(AttributeSeeder::class);
        DB::table('attributes')->where('slug', 'wifi')->update(['name_ar' => 'إنترنت', 'is_active' => false]);

        $this->seed(AttributeSeeder::class);

        $wifi = DB::table('attributes')->where('slug', 'wifi')->first();
        $this->assertSame('إنترنت', $wifi->name_ar);
        $this->assertEquals(0, $wifi->is_active);
        $this->assertSame(22, DB::table('attributes')->count());
    }
}
