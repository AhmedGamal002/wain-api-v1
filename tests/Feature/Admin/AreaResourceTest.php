<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\Areas\Pages\ManageAreas;
use App\Filament\Resources\Cities\Pages\ManageCities;
use App\Models\Area;
use App\Models\City;
use App\Models\User;
use Database\Seeders\AreaSeeder;
use Database\Seeders\DemoPlaceSeeder;
use Database\Seeders\RoleSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * A4 إدارة المدن والمناطق (02 القسم A4): المنطقة تحت مدينة، الاسم ما يتكررش جوه المدينة، الأسماء التانية
 * للبحث، منتصف المنطقة، وتفعيل المنطقة. Admin بيعدّل، والـ Moderator عرض فقط.
 */
class AreaResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleSeeder::class, AreaSeeder::class]);
        Filament::setCurrentPanel('admin');
    }

    private function asAdmin(): User
    {
        $admin = User::factory()->create()->assignRole('admin');
        $this->actingAs($admin);

        return $admin;
    }

    private function area(string $slug): Area
    {
        return Area::query()->where('slug', $slug)->firstOrFail();
    }

    private function city(string $slug): City
    {
        return City::query()->where('slug', $slug)->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function areaData(array $overrides = []): array
    {
        return $overrides + [
            'city_id' => $this->city('cairo')->id,
            'name_ar' => 'القطامية',
            'name_en' => 'Katameya',
            'aliases' => ['القطامية', 'Katameya Heights'],
            'center_lat' => 29.9987,
            'center_lng' => 31.4281,
            'is_active' => false,
        ];
    }

    /**
     * @return list<string>
     */
    private function apiSlugs(): array
    {
        return array_column($this->getJson('/api/v1/areas', ['Accept-Language' => 'ar'])->assertOk()->json('data'), 'slug');
    }

    public function test_page_access_follows_the_roles(): void
    {
        foreach (['/admin/areas', '/admin/cities'] as $url) {
            $this->get($url)->assertRedirect('/admin/login');
        }

        $this->actingAs(User::factory()->create());
        foreach (['/admin/areas', '/admin/cities'] as $url) {
            $this->get($url)->assertForbidden();
        }

        $this->actingAs(User::factory()->create()->assignRole('moderator'));
        foreach (['/admin/areas', '/admin/cities'] as $url) {
            $this->get($url)->assertOk();
        }

        $this->asAdmin();
        foreach (['/admin/areas', '/admin/cities'] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_admin_creates_an_area_with_slug_aliases_and_center(): void
    {
        $this->asAdmin();
        $last = (int) Area::query()->max('sort_order');

        Livewire::test(ManageAreas::class)
            ->callAction('create', $this->areaData())
            ->assertHasNoActionErrors();

        $area = $this->area('katameya');
        $this->assertSame($last + 1, $area->sort_order);
        $this->assertFalse($area->is_active);
        $this->assertSame(['القطامية', 'Katameya Heights'], $area->aliases);
        $this->assertEquals(29.9987, $area->center_lat);
        $this->assertSame($this->city('cairo')->id, $area->city_id);
    }

    public function test_arabic_name_is_unique_inside_a_city_but_can_repeat_in_another(): void
    {
        $this->asAdmin();

        // «المعادي» موجودة في القاهرة
        Livewire::test(ManageAreas::class)
            ->callAction('create', $this->areaData(['name_ar' => 'المعادي', 'name_en' => 'Maadi Two']))
            ->assertHasActionErrors(['name_ar' => 'unique']);

        // نفس الاسم في مدينة تانية مسموح
        Livewire::test(ManageAreas::class)
            ->callAction('create', $this->areaData([
                'city_id' => $this->city('giza')->id, 'name_ar' => 'المعادي', 'name_en' => 'Maadi Giza',
            ]))
            ->assertHasNoActionErrors();

        $this->assertSame(2, Area::query()->where('name_ar', 'المعادي')->count());
    }

    public function test_form_validates_required_fields_and_the_center_range(): void
    {
        $this->asAdmin();

        Livewire::test(ManageAreas::class)
            ->callAction('create', ['city_id' => null, 'name_ar' => '', 'name_en' => '', 'center_lat' => '', 'center_lng' => ''])
            ->assertHasActionErrors([
                'city_id' => 'required', 'name_ar' => 'required', 'name_en' => 'required',
                'center_lat' => 'required', 'center_lng' => 'required',
            ]);

        // إحداثيات برّه مصر (مثلاً 130.0 بدل 30.0)
        Livewire::test(ManageAreas::class)
            ->callAction('create', $this->areaData(['center_lat' => 130.0, 'center_lng' => 5.0]))
            ->assertHasActionErrors(['center_lat' => 'max', 'center_lng' => 'min']);

        Livewire::test(ManageAreas::class)
            ->callAction('create', $this->areaData(['center_lat' => 'abc']))
            ->assertHasActionErrors(['center_lat' => 'numeric']);
    }

    public function test_editing_updates_the_fields_but_never_the_slug(): void
    {
        $this->asAdmin();
        $zamalek = $this->area('zamalek');

        Livewire::test(ManageAreas::class)
            ->callAction(TestAction::make('edit')->table($zamalek), $this->areaData([
                'name_ar' => 'الزمالك الجديدة', 'name_en' => 'New Zamalek', 'slug' => 'hacked',
            ]))
            ->assertHasNoActionErrors();

        $zamalek->refresh();
        $this->assertSame('zamalek', $zamalek->slug);
        $this->assertSame('الزمالك الجديدة', $zamalek->name_ar);
    }

    public function test_activating_an_area_shows_it_in_the_api_at_once(): void
    {
        $this->asAdmin();
        $this->assertSame(['new-cairo'], $this->apiSlugs()); // بيملى الـ cache

        Livewire::test(ManageAreas::class)
            ->call('updateTableColumnState', 'is_active', (string) $this->area('zamalek')->id, true);

        $this->assertContains('zamalek', $this->apiSlugs());
    }

    public function test_reordering_areas_changes_the_api_order(): void
    {
        $this->asAdmin();
        Area::query()->update(['is_active' => true]);
        Area::forgetCatalogCache();
        $before = $this->apiSlugs();

        $ids = Area::query()->orderBy('sort_order')->pluck('id')->all();
        // أول منطقة (new-cairo) تروح آخر القائمة
        Livewire::test(ManageAreas::class)->call('reorderTable', [...array_slice($ids, 1), $ids[0]]);

        $after = $this->apiSlugs();
        $this->assertSame('new-cairo', $before[0]);
        $this->assertNotSame('new-cairo', $after[0]);
        // ترتيب الـ API: المدينة الأول ثم المنطقة، فالمنطقة اللي في آخر القاهرة هي اللي قبل أول منطقة في الجيزة
        $cairoAfter = array_values(array_filter($after, fn (string $slug) => $this->area($slug)->city_id === $this->city('cairo')->id));
        $this->assertSame('new-cairo', end($cairoAfter));
    }

    public function test_an_area_with_places_cannot_be_deleted_but_an_empty_one_can(): void
    {
        $this->seed(DemoPlaceSeeder::class);
        $admin = $this->asAdmin();

        $used = $this->area('new-cairo');
        $empty = $this->area('zamalek');
        $this->assertTrue($used->isInUse());
        $this->assertFalse($empty->isInUse());
        $this->assertTrue($admin->cannot('delete', $used));

        $component = Livewire::test(ManageAreas::class)
            ->assertActionHidden(TestAction::make('delete')->table($used))
            ->assertActionVisible(TestAction::make('delete')->table($empty));

        $component->callAction(TestAction::make('delete')->table($empty));

        $this->assertTrue(Area::query()->whereKey($used->id)->exists());
        $this->assertFalse(Area::query()->whereKey($empty->id)->exists());
    }

    public function test_hiding_a_city_hides_its_areas_in_the_api(): void
    {
        $this->asAdmin();
        Area::query()->where('slug', 'dokki')->update(['is_active' => true]);
        Area::forgetCatalogCache();
        $this->assertSame(['new-cairo', 'dokki'], $this->apiSlugs());

        Livewire::test(ManageCities::class)
            ->call('updateTableColumnState', 'is_active', (string) $this->city('giza')->id, false);

        $this->assertSame(['new-cairo'], $this->apiSlugs());
    }

    public function test_a_city_with_areas_cannot_be_deleted_but_an_empty_one_can(): void
    {
        $admin = $this->asAdmin();
        $empty = City::query()->create(['name_ar' => 'الإسكندرية', 'name_en' => 'Alexandria']);
        $this->assertSame('alexandria', $empty->slug);

        $cairo = $this->city('cairo');
        $this->assertTrue($admin->cannot('delete', $cairo));
        $this->assertTrue($admin->can('delete', $empty));

        Livewire::test(ManageCities::class)
            ->assertActionHidden(TestAction::make('delete')->table($cairo))
            ->callAction(TestAction::make('delete')->table($empty));

        $this->assertFalse(City::query()->whereKey($empty->id)->exists());
        $this->assertTrue(City::query()->whereKey($cairo->id)->exists());
    }

    public function test_admin_creates_and_edits_a_city_without_changing_its_slug(): void
    {
        $this->asAdmin();

        Livewire::test(ManageCities::class)
            ->callAction('create', ['name_ar' => 'الإسكندرية', 'name_en' => 'Alexandria', 'is_active' => true])
            ->assertHasNoActionErrors();

        $alex = $this->city('alexandria');

        Livewire::test(ManageCities::class)
            ->callAction(TestAction::make('edit')->table($alex), ['name_ar' => 'إسكندرية', 'name_en' => 'Alex', 'is_active' => true, 'slug' => 'hacked'])
            ->assertHasNoActionErrors();

        $this->assertSame('alexandria', $alex->fresh()->slug);
        $this->assertSame('إسكندرية', $alex->fresh()->name_ar);
    }

    public function test_moderator_can_view_but_not_change_anything(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('moderator'));
        $zamalek = $this->area('zamalek');

        Livewire::test(ManageAreas::class)
            // الجدول بيعرض 10 صفوف في الصفحة، فبنفحص أول 5 بس
            ->assertCanSeeTableRecords(Area::query()->orderBy('sort_order')->limit(5)->get(), inOrder: true)
            ->assertActionHidden('create')
            ->assertActionHidden(TestAction::make('edit')->table($zamalek))
            ->call('updateTableColumnState', 'is_active', (string) $zamalek->id, true);

        $this->assertFalse($zamalek->fresh()->is_active);
    }

    public function test_area_changes_are_recorded_in_the_activity_log(): void
    {
        $admin = $this->asAdmin();

        Livewire::test(ManageAreas::class)
            ->callAction(TestAction::make('edit')->table($this->area('zamalek')), $this->areaData([
                'name_ar' => 'الزمالك', 'name_en' => 'Zamalek', 'center_lat' => 30.07, 'center_lng' => 31.22,
            ]))
            ->assertHasNoActionErrors();

        $log = Activity::query()->where('log_name', 'areas')->latest('id')->firstOrFail();

        $this->assertSame('updated', $log->event);
        $this->assertSame($admin->id, $log->causer_id);
    }
}
