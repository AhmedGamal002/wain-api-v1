<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\Categories\Pages\ManageCategories;
use App\Models\Category;
use App\Models\User;
use Database\Seeders\CategorySeeder;
use Database\Seeders\DemoPlaceSeeder;
use Database\Seeders\RoleSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * A2 إدارة التصنيفات (02 القسم A2): الإخفاء، الترتيب بالسحب، ومنع حذف تصنيف فيه أماكن.
 * Admin بيعدّل، والـ Moderator عرض فقط، والمستخدم العادي 403.
 */
class CategoryResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleSeeder::class, CategorySeeder::class]);
        Filament::setCurrentPanel('admin');
    }

    private function asAdmin(): User
    {
        $admin = User::factory()->create()->assignRole('admin');
        $this->actingAs($admin);

        return $admin;
    }

    private function asModerator(): User
    {
        $moderator = User::factory()->create()->assignRole('moderator');
        $this->actingAs($moderator);

        return $moderator;
    }

    private function category(string $slug): Category
    {
        return Category::query()->where('slug', $slug)->firstOrFail();
    }

    private function emptyCategory(): Category
    {
        return Category::query()->create([
            'name_ar' => 'فاضي', 'name_en' => 'Empty', 'icon' => '⭕', 'color' => '#000000',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(array $overrides = []): array
    {
        return $overrides + [
            'name_ar' => 'مولات',
            'name_en' => 'Shopping Malls',
            'icon' => '🛍️',
            'color' => '#1E88E5',
            'is_active' => true,
        ];
    }

    /**
     * @return list<string>
     */
    private function apiSlugs(): array
    {
        return array_column(
            $this->getJson('/api/v1/categories', ['Accept-Language' => 'ar'])->assertOk()->json('data'),
            'slug',
        );
    }

    public function test_page_access_follows_the_roles(): void
    {
        $this->get('/admin/categories')->assertRedirect('/admin/login');

        $this->actingAs(User::factory()->create())->get('/admin/categories')->assertForbidden();

        $this->asModerator();
        $this->get('/admin/categories')->assertOk();

        $this->asAdmin();
        $this->get('/admin/categories')->assertOk();
    }

    public function test_list_is_ordered_and_counts_published_places_primary_or_additional(): void
    {
        $this->seed(DemoPlaceSeeder::class);
        $this->asAdmin();

        $expected = DB::table('place_category')
            ->join('places', 'places.id', '=', 'place_category.place_id')
            ->where('places.status', 'active')
            ->whereNull('places.deleted_at')
            ->selectRaw('place_category.category_id, count(*) as c')
            ->groupBy('place_category.category_id')
            ->pluck('c', 'category_id');
        $this->assertGreaterThan(0, $expected->sum());

        $categories = Category::query()->orderBy('sort_order')->get();

        $component = Livewire::test(ManageCategories::class)->assertCanSeeTableRecords($categories, inOrder: true);

        foreach ($categories as $category) {
            $component->assertTableColumnStateSet('places_count', (int) ($expected[$category->id] ?? 0), $category);
        }
    }

    public function test_admin_creates_a_category_with_an_automatic_slug_and_last_position(): void
    {
        $this->asAdmin();
        $last = (int) Category::query()->max('sort_order');

        Livewire::test(ManageCategories::class)
            ->callAction('create', $this->formData())
            ->assertHasNoActionErrors();

        $category = $this->category('shopping-malls');
        $this->assertSame($last + 1, $category->sort_order);
        $this->assertTrue($category->is_active);

        // اسم إنجليزي متكرر: الـ slug بيتميّز برقم
        Livewire::test(ManageCategories::class)
            ->callAction('create', $this->formData(['name_ar' => 'مولات تانية', 'color' => '#1E88E6']))
            ->assertHasNoActionErrors();

        $this->assertSame(2, Category::query()->where('name_en', 'Shopping Malls')->count());
        $this->assertTrue(Category::query()->where('slug', 'shopping-malls-2')->exists());
    }

    public function test_form_validates_lengths_and_color(): void
    {
        $this->asAdmin();

        Livewire::test(ManageCategories::class)
            ->callAction('create', $this->formData([
                'name_ar' => str_repeat('ا', 41),
                'name_en' => str_repeat('a', 41),
                'color' => 'red',
            ]))
            ->assertHasActionErrors(['name_ar' => 'max', 'name_en' => 'max', 'color' => 'regex']);

        Livewire::test(ManageCategories::class)
            ->callAction('create', ['name_ar' => '', 'name_en' => '', 'icon' => '', 'color' => ''])
            ->assertHasActionErrors(['name_ar' => 'required', 'name_en' => 'required', 'icon' => 'required', 'color' => 'required']);

        $this->assertFalse(Category::query()->where('name_en', 'like', 'aaaa%')->exists());
    }

    public function test_editing_updates_the_fields_but_never_the_slug(): void
    {
        $this->asAdmin();
        $cafes = $this->category('cafes');

        Livewire::test(ManageCategories::class)
            ->callAction(TestAction::make('edit')->table($cafes), $this->formData([
                'name_ar' => 'كافيهات وقهوة',
                'name_en' => 'Cafes and Coffee',
                'icon' => '☕',
                'color' => '#112233',
                // محاولة تعديل الـ slug من غير الفورم (الحقل disabled)
                'slug' => 'hacked',
            ]))
            ->assertHasNoActionErrors();

        $cafes->refresh();
        $this->assertSame('cafes', $cafes->slug);
        $this->assertSame('كافيهات وقهوة', $cafes->name_ar);
        $this->assertSame('#112233', $cafes->color);

        // وعلى مستوى الموديل كمان
        $cafes->update(['slug' => 'something-else']);
        $this->assertSame('cafes', $cafes->fresh()->slug);
    }

    public function test_hiding_a_category_shows_up_in_the_api_without_waiting_for_the_cache(): void
    {
        $this->asAdmin();
        $this->assertContains('cafes', $this->apiSlugs()); // بيملى الـ cache

        Livewire::test(ManageCategories::class)
            ->call('updateTableColumnState', 'is_active', (string) $this->category('cafes')->id, false);

        $this->assertNotContains('cafes', $this->apiSlugs());
    }

    public function test_reordering_by_drag_changes_the_api_order(): void
    {
        $this->asAdmin();
        $before = $this->apiSlugs(); // بيملى الـ cache
        $this->assertSame('cafes', $before[0]);

        $ids = Category::query()->orderBy('sort_order')->pluck('id')->all();
        $reordered = [...array_slice($ids, 1), $ids[0]]; // أول واحد يروح آخر القائمة

        Livewire::test(ManageCategories::class)->call('reorderTable', $reordered);

        $after = $this->apiSlugs();
        $this->assertSame('cafes', end($after));
        $this->assertSame($before[1], $after[0]);
    }

    public function test_a_category_with_places_cannot_be_deleted_but_an_empty_one_can(): void
    {
        $this->seed(DemoPlaceSeeder::class);
        $admin = $this->asAdmin();

        $withPlaces = $this->category('cafes');
        $empty = $this->emptyCategory();
        $this->assertTrue($withPlaces->hasPlaces());
        $this->assertFalse($empty->hasPlaces());

        // الزرار مخفي، والـ Policy بترفض كمان لو اتبعت الطلب مباشرة
        $this->assertTrue($admin->cannot('delete', $withPlaces));
        $this->assertTrue($admin->can('delete', $empty));

        $component = Livewire::test(ManageCategories::class)
            ->assertActionHidden(TestAction::make('delete')->table($withPlaces))
            ->assertActionVisible(TestAction::make('delete')->table($empty));

        $component->callAction(TestAction::make('delete')->table($empty));

        $this->assertTrue(Category::query()->whereKey($withPlaces->id)->exists());
        $this->assertFalse(Category::query()->whereKey($empty->id)->exists());
    }

    public function test_a_soft_deleted_place_still_blocks_the_delete(): void
    {
        $this->seed(DemoPlaceSeeder::class);

        $category = $this->emptyCategory();
        $placeId = DB::table('places')->orderBy('id')->value('id');
        DB::table('place_category')->insert(['place_id' => $placeId, 'category_id' => $category->id]);
        DB::table('places')->where('id', $placeId)->update(['deleted_at' => now()]);

        $this->assertTrue($category->hasPlaces());
    }

    public function test_bulk_delete_skips_categories_with_places(): void
    {
        $this->seed(DemoPlaceSeeder::class);
        $this->asAdmin();

        $withPlaces = $this->category('cafes');
        $empty = $this->emptyCategory();

        Livewire::test(ManageCategories::class)
            ->callTableBulkAction('delete', [$withPlaces, $empty]);

        $this->assertTrue(Category::query()->whereKey($withPlaces->id)->exists());
        $this->assertFalse(Category::query()->whereKey($empty->id)->exists());
    }

    public function test_moderator_can_view_but_not_change_anything(): void
    {
        $this->asModerator();
        $cafes = $this->category('cafes');
        $empty = $this->emptyCategory();

        Livewire::test(ManageCategories::class)
            ->assertCanSeeTableRecords(Category::query()->get())
            ->assertActionHidden('create')
            ->assertActionHidden(TestAction::make('edit')->table($cafes))
            ->assertActionHidden(TestAction::make('delete')->table($empty))
            ->call('updateTableColumnState', 'is_active', (string) $cafes->id, false);

        // التعديل المباشر مرفوض برضه، ومفيش حاجة اتغيّرت
        $this->assertTrue($cafes->fresh()->is_active);
    }

    public function test_changes_are_recorded_in_the_activity_log_with_the_causer(): void
    {
        $admin = $this->asAdmin();

        Livewire::test(ManageCategories::class)
            ->callAction(TestAction::make('edit')->table($this->category('restaurants')), $this->formData([
                'name_ar' => 'مطاعم جديدة',
                'name_en' => 'Restaurants',
                'icon' => '🍽️',
                'color' => '#E8593C',
            ]))
            ->assertHasNoActionErrors();

        $log = Activity::query()->where('log_name', 'categories')->latest('id')->firstOrFail();

        $this->assertSame('updated', $log->event);
        $this->assertSame($admin->id, $log->causer_id);
        $this->assertSame('مطاعم جديدة', $log->properties['attributes']['name_ar']);
    }
}
