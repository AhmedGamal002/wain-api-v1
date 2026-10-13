<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\Places\Pages\ListPlaces;
use App\Filament\Resources\Places\PlaceResource;
use App\Models\Area;
use App\Models\Category;
use App\Models\City;
use App\Models\Place;
use App\Models\User;
use Database\Seeders\AttributeSeeder;
use Database\Seeders\CategorySeeder;
use Database\Seeders\CityAreaSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A1 صفحة قائمة الأماكن (03 القسم 4.2): التابز بعددها، الأعمدة، الفلاتر، البحث، وصلاحيات الدخول.
 */
class PlaceResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleSeeder::class, SettingSeeder::class, CategorySeeder::class, CityAreaSeeder::class, AttributeSeeder::class]);
        Filament::setCurrentPanel('admin');
    }

    private function asAdmin(): User
    {
        $admin = User::factory()->create(['name' => 'جمال'])->assignRole('admin');
        $this->actingAs($admin);

        return $admin;
    }

    private function asModerator(): User
    {
        $moderator = User::factory()->create(['name' => 'معلوم'])->assignRole('moderator');
        $this->actingAs($moderator);

        return $moderator;
    }

    private function list()
    {
        return Livewire::test(ListPlaces::class);
    }

    public function test_page_access_follows_the_roles(): void
    {
        $this->get('/admin/places')->assertRedirect('/admin/login');

        $this->actingAs(User::factory()->create());
        $this->get('/admin/places')->assertForbidden();

        $this->actingAs(User::factory()->create()->assignRole('owner'));
        $this->get('/admin/places')->assertForbidden();

        $this->actingAs(User::factory()->create()->assignRole('moderator'));
        $this->get('/admin/places')->assertOk();

        $this->actingAs(User::factory()->create()->assignRole('admin'));
        $this->get('/admin/places')->assertOk();
    }

    public function test_the_resource_is_under_the_content_group(): void
    {
        $this->asAdmin();

        $this->assertSame('المحتوى', PlaceResource::getNavigationGroup());
        $this->assertSame('الأماكن', PlaceResource::getNavigationLabel());
    }

    public function test_the_navigation_badge_counts_places_pending_review(): void
    {
        $this->asAdmin();
        $this->assertNull(PlaceResource::getNavigationBadge());

        Place::factory()->count(2)->status(Place::STATUS_PENDING)->create();
        Place::factory()->published()->create();

        $this->assertSame('2', PlaceResource::getNavigationBadge());
    }

    public function test_tabs_show_a_count_each_and_filter_by_status(): void
    {
        $this->asAdmin();
        $pending = Place::factory()->count(2)->status(Place::STATUS_PENDING)->create();
        $active = Place::factory()->published()->create();
        $hidden = Place::factory()->status(Place::STATUS_HIDDEN)->create();
        $draft = Place::factory()->create();
        $rejected = Place::factory()->status(Place::STATUS_REJECTED)->create();
        $closed = Place::factory()->status(Place::STATUS_CLOSED)->create();
        Place::factory()->published()->create()->delete();

        $list = $this->list();
        $tabs = $list->instance()->getTabs();

        $this->assertSame(['all', 'pending', 'active', 'hidden', 'draft', 'rejected', 'closed'], array_keys($tabs));
        $this->assertSame(
            ['all' => 7, 'pending' => 2, 'active' => 1, 'hidden' => 1, 'draft' => 1, 'rejected' => 1, 'closed' => 1],
            collect($tabs)->map(fn ($tab) => (int) $tab->getBadge())->all(),
        );
        $this->assertSame('الكل', $tabs['all']->getLabel());
        $this->assertSame('قيد المراجعة', $tabs['pending']->getLabel());

        $list->assertCanSeeTableRecords([...$pending, $active, $hidden, $draft, $rejected, $closed]);

        $list->set('activeTab', 'pending')
            ->assertCanSeeTableRecords($pending)
            ->assertCanNotSeeTableRecords([$active, $hidden, $draft, $rejected, $closed]);

        $list->set('activeTab', 'active')
            ->assertCanSeeTableRecords([$active])
            ->assertCountTableRecords(1);

        foreach (['hidden' => $hidden, 'draft' => $draft, 'rejected' => $rejected, 'closed' => $closed] as $tab => $place) {
            $list->set('activeTab', $tab)->assertCanSeeTableRecords([$place])->assertCountTableRecords(1);
        }
    }

    public function test_every_column_renders(): void
    {
        $this->asAdmin();
        Place::factory()->published()->create(['phone' => '+201000000001', 'rating_avg' => 4.3, 'rating_count' => 120, 'verified_at' => now()->subDays(40)]);
        Place::factory()->create();

        $list = $this->list();

        foreach (['cover', 'name_ar', 'area.name_ar', 'primaryCategory.name_ar', 'status', 'completeness', 'listing_tier',
            'rating_avg', 'verified_at', 'updated_at', 'phone', 'createdBy.name', 'published_at', 'views_30d'] as $column) {
            $list->assertTableColumnExists($column);
        }
    }

    public function test_default_columns_follow_the_spec_and_the_extra_ones_are_hidden(): void
    {
        $this->asAdmin();
        $table = $this->list()->instance()->getTable();

        $hidden = collect($table->getColumns())
            ->filter(fn ($column) => $column->isToggledHiddenByDefault())
            ->map(fn ($column) => $column->getName())
            ->values()
            ->all();

        $this->assertEqualsCanonicalizing(['phone', 'createdBy.name', 'published_at', 'views_30d'], $hidden);
    }

    public function test_name_column_shows_arabic_with_english_below_and_searches_names_slug_and_phone(): void
    {
        $this->asAdmin();
        $cilantro = Place::factory()->create(['name_ar' => 'سيلانترو', 'name_en' => 'Cilantro', 'phone' => '+201001234567']);
        $other = Place::factory()->create(['name_ar' => 'تاون ساوث', 'name_en' => 'Town South', 'phone' => '+201111111111']);

        $this->list()
            ->searchTable('سيلان')
            ->assertCanSeeTableRecords([$cilantro])->assertCanNotSeeTableRecords([$other]);

        $this->list()
            ->searchTable('town sou')
            ->assertCanSeeTableRecords([$other])->assertCanNotSeeTableRecords([$cilantro]);

        $this->list()
            ->searchTable('cilantro')
            ->assertCanSeeTableRecords([$cilantro])->assertCanNotSeeTableRecords([$other]);

        // رقم محلي بالصفر الأول على رقم مخزّن بصيغة +20
        $this->list()
            ->searchTable('01001234567')
            ->assertCanSeeTableRecords([$cilantro])->assertCanNotSeeTableRecords([$other]);

        $this->list()
            ->searchTable('100_234')
            ->assertCanNotSeeTableRecords([$cilantro, $other]);
    }

    public function test_search_treats_like_wildcards_as_plain_text(): void
    {
        $this->asAdmin();
        $place = Place::factory()->create(['name_en' => 'Place 100']);

        $this->list()->searchTable('%')->assertCanNotSeeTableRecords([$place]);
        $this->list()->searchTable('_')->assertCanNotSeeTableRecords([$place]);
    }

    public function test_status_badge_uses_the_arabic_label_and_the_reason_as_tooltip(): void
    {
        $this->asAdmin();
        $hidden = Place::factory()->status(Place::STATUS_HIDDEN)->create(['status_reason' => 'شكوى من صاحب المكان']);
        $draft = Place::factory()->create();

        $this->list()
            ->assertTableColumnFormattedStateSet('status', 'مخفي', $hidden)
            ->assertTableColumnFormattedStateSet('status', 'مسودة', $draft);
    }

    public function test_completeness_column_counts_the_publish_conditions(): void
    {
        $this->asAdmin();
        $complete = Place::factory()->complete()->create();
        $draft = Place::factory()->create();

        $html = $this->list()->assertCanSeeTableRecords([$complete, $draft])->html();

        $this->assertStringContainsString('9 من 9', $html);
        $this->assertStringContainsString('4 من 9', $html);
        // الناقص بيتسمّى في الـ tooltip
        $this->assertStringContainsString('ناقص: الوصف بالعربي، 3 خصائص على الأقل، سعر الفرد، المواعيد، صورة الغلاف', $html);
    }

    public function test_the_list_does_not_run_a_query_per_row_for_the_completeness_column(): void
    {
        $this->asAdmin();
        Place::factory()->count(3)->complete()->create();

        // أول طلب بيحمّل cache الأدوار والصلاحيات (spatie)، فبنسخّنه قبل القياس
        $this->list();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->list();
        $few = count(DB::getQueryLog());
        DB::disableQueryLog();

        Place::factory()->count(6)->complete()->create();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->list();
        $many = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($few, $many);
    }

    public function test_verified_column_shows_days_and_flags_missing_or_stale(): void
    {
        $this->asAdmin();
        $recent = Place::factory()->create(['verified_at' => now()->subDays(40)]);
        $stale = Place::factory()->create(['verified_at' => now()->subDays(120)]);
        $never = Place::factory()->create(['verified_at' => null]);

        $this->list()
            ->assertTableColumnFormattedStateSet('verified_at', 'من 40 يوم', $recent)
            ->assertTableColumnFormattedStateSet('verified_at', 'من 120 يوم', $stale)
            ->assertTableColumnFormattedStateSet('verified_at', 'ما اتأكدش', $never);
    }

    public function test_rating_column_shows_average_and_count(): void
    {
        $this->asAdmin();
        $rated = Place::factory()->create(['rating_avg' => 4.3, 'rating_count' => 120]);
        $unrated = Place::factory()->create();

        $this->list()
            ->assertTableColumnFormattedStateSet('rating_avg', '4.3 (120)', $rated)
            ->assertTableColumnFormattedStateSet('rating_avg', '—', $unrated);
    }

    public function test_tier_column_shows_the_remaining_days_in_red_under_seven(): void
    {
        $this->asAdmin();
        Place::factory()->create(['listing_tier' => 'premium', 'tier_starts_at' => now()->subDays(10), 'tier_ends_at' => now()->addDays(12)->addHour()]);
        Place::factory()->create(['listing_tier' => 'featured', 'tier_starts_at' => now()->subDays(10), 'tier_ends_at' => now()->addDays(3)->addHour()]);

        $html = $this->list()->html();

        $this->assertStringContainsString('باقي 13 يوم', $html);
        $this->assertStringContainsString('<span class="text-danger-600 dark:text-danger-400">باقي 4 يوم</span>', $html);
    }

    public function test_sorting_by_area_rating_verified_and_tier_works(): void
    {
        $this->asAdmin();
        $zamalek = Area::query()->create([
            'city_id' => City::query()->value('id'), 'slug' => 'zamalek', 'name_ar' => 'الزمالك', 'name_en' => 'Zamalek',
            'center_lat' => 30.06, 'center_lng' => 31.22, 'is_active' => true,
        ]);
        $newCairo = Place::factory()->create(['rating_avg' => 4.5, 'rating_count' => 10, 'listing_tier' => 'free']);
        $inZamalek = Place::factory()->create(['area_id' => $zamalek->id, 'rating_avg' => 3.0, 'rating_count' => 10, 'listing_tier' => 'premium']);

        $this->list()->sortTable('rating_avg', 'desc')->assertCanSeeTableRecords([$newCairo, $inZamalek], inOrder: true);
        $this->list()->sortTable('rating_avg', 'asc')->assertCanSeeTableRecords([$inZamalek, $newCairo], inOrder: true);
        // premium الأول (tier_rank 1) في الترتيب التصاعدي
        $this->list()->sortTable('listing_tier', 'asc')->assertCanSeeTableRecords([$inZamalek, $newCairo], inOrder: true);
        $this->list()->sortTable('area.name_ar', 'asc')->assertCountTableRecords(2);
        $this->list()->sortTable('verified_at', 'desc')->assertCountTableRecords(2);
    }

    public function test_default_sort_is_latest_edit_first(): void
    {
        $this->asAdmin();
        $old = Place::factory()->create();
        $old->forceFill(['updated_at' => now()->subDays(5)])->saveQuietly();
        $new = Place::factory()->create();

        $this->list()->assertCanSeeTableRecords([$new, $old], inOrder: true);
    }

    public function test_location_filter_by_city_and_area(): void
    {
        $this->asAdmin();
        $alex = City::query()->create(['slug' => 'alexandria', 'name_ar' => 'الإسكندرية', 'name_en' => 'Alexandria', 'is_active' => true]);
        $smouha = Area::query()->create([
            'city_id' => $alex->id, 'slug' => 'smouha', 'name_ar' => 'سموحة', 'name_en' => 'Smouha',
            'center_lat' => 31.2, 'center_lng' => 29.94, 'is_active' => true,
        ]);
        $inCairo = Place::factory()->create();
        $inAlex = Place::factory()->create(['city_id' => $alex->id, 'area_id' => $smouha->id]);

        $this->list()
            ->filterTable('location', ['city_id' => $alex->id])
            ->assertCanSeeTableRecords([$inAlex])->assertCanNotSeeTableRecords([$inCairo]);

        $this->list()
            ->filterTable('location', ['city_id' => $alex->id, 'area_id' => $smouha->id])
            ->assertCanSeeTableRecords([$inAlex])->assertCanNotSeeTableRecords([$inCairo]);

        $this->list()
            ->filterTable('location', ['city_id' => City::query()->where('slug', 'cairo')->value('id')])
            ->assertCanSeeTableRecords([$inCairo])->assertCanNotSeeTableRecords([$inAlex]);
    }

    public function test_category_filter_matches_any_category_of_the_place_not_just_the_primary(): void
    {
        $this->asAdmin();
        $cafes = Category::query()->where('slug', 'cafes')->firstOrFail();
        $rooftops = Category::query()->where('slug', 'rooftops')->firstOrFail();
        $desserts = Category::query()->where('slug', 'desserts')->firstOrFail();

        $cafeAndRooftop = Place::factory()->create(['primary_category_id' => $cafes->id]);
        $cafeAndRooftop->categories()->attach($rooftops->id);
        $dessertsOnly = Place::factory()->create(['primary_category_id' => $desserts->id]);

        $this->list()
            ->filterTable('categories', [$rooftops->id])
            ->assertCanSeeTableRecords([$cafeAndRooftop])->assertCanNotSeeTableRecords([$dessertsOnly]);

        $this->list()
            ->filterTable('categories', [$rooftops->id, $desserts->id])
            ->assertCanSeeTableRecords([$cafeAndRooftop, $dessertsOnly]);
    }

    public function test_tier_filter_includes_places_expiring_within_seven_days(): void
    {
        $this->asAdmin();
        $free = Place::factory()->create();
        $expiring = Place::factory()->create(['listing_tier' => 'featured', 'tier_starts_at' => now()->subDays(20), 'tier_ends_at' => now()->addDays(5)]);
        $later = Place::factory()->create(['listing_tier' => 'premium', 'tier_starts_at' => now()->subDays(20), 'tier_ends_at' => now()->addDays(40)]);

        $this->list()->filterTable('tier', 'expiring')
            ->assertCanSeeTableRecords([$expiring])->assertCanNotSeeTableRecords([$free, $later]);
        $this->list()->filterTable('tier', 'premium')
            ->assertCanSeeTableRecords([$later])->assertCanNotSeeTableRecords([$free, $expiring]);
        $this->list()->filterTable('tier', 'free')
            ->assertCanSeeTableRecords([$free])->assertCanNotSeeTableRecords([$expiring, $later]);
    }

    public function test_incomplete_no_cover_and_stale_toggles(): void
    {
        $this->asAdmin();
        $complete = Place::factory()->complete()->create(['verified_at' => now()->subDays(10)]);
        $incomplete = Place::factory()->create(['verified_at' => now()->subDays(100)]);
        $noCover = Place::factory()->complete()->create(['verified_at' => now()->subDays(10)]);
        $noCover->forceFill(['cover_photo_id' => null])->saveQuietly();

        $this->list()->filterTable('incomplete', true)
            ->assertCanSeeTableRecords([$incomplete, $noCover])->assertCanNotSeeTableRecords([$complete]);

        $this->list()->filterTable('no_cover', true)
            ->assertCanSeeTableRecords([$incomplete, $noCover])->assertCanNotSeeTableRecords([$complete]);

        $this->list()->filterTable('stale', true)
            ->assertCanSeeTableRecords([$incomplete])->assertCanNotSeeTableRecords([$complete, $noCover]);
    }

    public function test_claimed_filter_is_all_yes_or_no(): void
    {
        $this->asAdmin();
        $owner = User::factory()->create()->assignRole('owner');
        $claimed = Place::factory()->create(['claimed_by' => $owner->id]);
        $unclaimed = Place::factory()->create();

        $this->list()->assertCanSeeTableRecords([$claimed, $unclaimed]);
        $this->list()->filterTable('claimed', true)
            ->assertCanSeeTableRecords([$claimed])->assertCanNotSeeTableRecords([$unclaimed]);
        $this->list()->filterTable('claimed', false)
            ->assertCanSeeTableRecords([$unclaimed])->assertCanNotSeeTableRecords([$claimed]);
    }

    public function test_added_by_filter_lists_team_members_and_filters(): void
    {
        $admin = $this->asAdmin();
        $moderator = User::factory()->create(['name' => 'معلوم'])->assignRole('moderator');
        $byAdmin = Place::factory()->create(['created_by' => $admin->id]);
        $byModerator = Place::factory()->create(['created_by' => $moderator->id]);

        $this->list()
            ->filterTable('created_by', $moderator->id)
            ->assertCanSeeTableRecords([$byModerator])->assertCanNotSeeTableRecords([$byAdmin]);
    }

    public function test_a_moderator_opens_on_my_places_and_an_admin_sees_everything(): void
    {
        $moderator = $this->asModerator();
        $admin = User::factory()->create()->assignRole('admin');
        $mine = Place::factory()->create(['created_by' => $moderator->id]);
        $theirs = Place::factory()->create(['created_by' => $admin->id]);

        $this->list()
            ->assertCanSeeTableRecords([$mine])->assertCanNotSeeTableRecords([$theirs])
            ->assertTableFilterExists('created_by');

        // الفلتر محفوظ في الـ session لكل مستخدم، فالأدمن بيبدأ من جلسة جديدة
        session()->flush();
        $this->actingAs($admin);
        $this->list()->assertCanSeeTableRecords([$mine, $theirs]);
    }

    public function test_trashed_places_are_hidden_and_only_an_admin_can_show_them(): void
    {
        $this->asAdmin();
        $live = Place::factory()->create();
        $deleted = Place::factory()->create();
        $deleted->delete();

        $this->list()->assertCanSeeTableRecords([$live])->assertCanNotSeeTableRecords([$deleted]);
        $this->list()->filterTable('trashed', false)->assertCanSeeTableRecords([$deleted])->assertCanNotSeeTableRecords([$live]);
        $this->list()->filterTable('trashed', true)->assertCanSeeTableRecords([$live, $deleted]);

        $this->actingAs(User::factory()->create()->assignRole('moderator'));
        $moderatorList = $this->list();
        $this->assertNull($moderatorList->instance()->getTable()->getFilter('trashed'));
        $moderatorList->assertCanSeeTableRecords([$live])->assertCanNotSeeTableRecords([$deleted]);
    }

    public function test_views_in_30_days_sums_only_view_events_in_the_window(): void
    {
        $this->asAdmin();
        $place = Place::factory()->published()->create();
        DB::table('place_stats_daily')->insert([
            ['place_id' => $place->id, 'date' => now()->subDays(2)->toDateString(), 'type' => 'view', 'count' => 10],
            ['place_id' => $place->id, 'date' => now()->subDays(29)->toDateString(), 'type' => 'view', 'count' => 5],
            ['place_id' => $place->id, 'date' => now()->subDays(45)->toDateString(), 'type' => 'view', 'count' => 100],
            ['place_id' => $place->id, 'date' => now()->subDays(2)->toDateString(), 'type' => 'call', 'count' => 7],
        ]);

        $this->list()->assertTableColumnStateSet('views_30d', '15', $place);
    }

    public function test_the_empty_state_says_there_are_no_places_yet(): void
    {
        $this->asAdmin();

        $this->list()->assertSee('مفيش أماكن لسه');
    }

    public function test_pagination_defaults_to_25_with_50_and_100_options(): void
    {
        $this->asAdmin();
        $table = $this->list()->instance()->getTable();

        $this->assertSame([25, 50, 100], $table->getPaginationPageOptions());
        $this->assertSame(25, $table->getDefaultPaginationPageOption());
    }
}
