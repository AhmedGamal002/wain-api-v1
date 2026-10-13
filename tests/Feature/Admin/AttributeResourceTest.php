<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\Attributes\Pages\ManageAttributes;
use App\Models\Attribute;
use App\Models\User;
use Database\Seeders\AttributeSeeder;
use Database\Seeders\DemoPlaceSeeder;
use Database\Seeders\RoleSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * A3 إدارة الخصائص (02 القسم A3): المجموعات، الـ chips السريعة (أقصى 6)، الترتيب بالسحب، ومنع حذف
 * خاصية مستخدمة. Admin بيعدّل، والـ Moderator عرض فقط.
 */
class AttributeResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleSeeder::class, AttributeSeeder::class]);
        Filament::setCurrentPanel('admin');
    }

    private function asAdmin(): User
    {
        $admin = User::factory()->create()->assignRole('admin');
        $this->actingAs($admin);

        return $admin;
    }

    private function attribute(string $slug): Attribute
    {
        return Attribute::query()->where('slug', $slug)->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(array $overrides = []): array
    {
        return $overrides + [
            'name_ar' => 'جلسة خارجية',
            'name_en' => 'Terrace',
            'group' => 'vibe',
            'icon' => '🌤️',
            'is_filterable' => true,
            'is_quick_filter' => false,
            'is_active' => true,
        ];
    }

    /**
     * @return list<string>
     */
    private function apiSlugs(): array
    {
        return collect($this->getJson('/api/v1/attributes', ['Accept-Language' => 'ar'])->assertOk()->json('data'))
            ->flatMap(fn ($group) => array_column($group['items'], 'slug'))
            ->all();
    }

    public function test_page_access_follows_the_roles(): void
    {
        $this->get('/admin/attributes')->assertRedirect('/admin/login');

        $this->actingAs(User::factory()->create())->get('/admin/attributes')->assertForbidden();

        $this->actingAs(User::factory()->create()->assignRole('moderator'))->get('/admin/attributes')->assertOk();

        $this->asAdmin();
        $this->get('/admin/attributes')->assertOk();
    }

    public function test_admin_creates_an_attribute_with_an_automatic_slug_at_the_end(): void
    {
        $this->asAdmin();
        $last = (int) Attribute::query()->max('sort_order');

        Livewire::test(ManageAttributes::class)
            ->callAction('create', $this->formData())
            ->assertHasNoActionErrors();

        $attribute = $this->attribute('terrace');
        $this->assertSame($last + 1, $attribute->sort_order);
        $this->assertSame('vibe', $attribute->group);
        $this->assertContains('terrace', $this->apiSlugs());
    }

    public function test_form_validates_lengths_and_the_group(): void
    {
        $this->asAdmin();

        Livewire::test(ManageAttributes::class)
            ->callAction('create', $this->formData([
                'name_ar' => str_repeat('ا', 31),
                'name_en' => str_repeat('a', 31),
                'group' => 'unknown-group',
            ]))
            ->assertHasActionErrors(['name_ar' => 'max', 'name_en' => 'max', 'group' => 'in']);

        Livewire::test(ManageAttributes::class)
            ->callAction('create', ['name_ar' => '', 'name_en' => '', 'group' => '', 'icon' => ''])
            ->assertHasActionErrors(['name_ar' => 'required', 'name_en' => 'required', 'group' => 'required', 'icon' => 'required']);
    }

    public function test_editing_never_changes_the_slug(): void
    {
        $this->asAdmin();
        $wifi = $this->attribute('wifi');

        Livewire::test(ManageAttributes::class)
            ->callAction(TestAction::make('edit')->table($wifi), $this->formData([
                'name_ar' => 'إنترنت', 'name_en' => 'Internet', 'group' => 'services', 'slug' => 'hacked',
            ]))
            ->assertHasNoActionErrors();

        $this->assertSame('wifi', $wifi->fresh()->slug);
        $this->assertSame('إنترنت', $wifi->fresh()->name_ar);
    }

    public function test_a_seventh_quick_filter_is_refused_but_an_existing_one_can_be_edited(): void
    {
        $this->asAdmin();
        $this->assertSame(Attribute::MAX_QUICK_FILTERS, Attribute::query()->where('is_quick_filter', true)->count());

        // الـ 7 الجديدة
        Livewire::test(ManageAttributes::class)
            ->callAction('create', $this->formData(['is_quick_filter' => true]))
            ->assertHasActionErrors(['is_quick_filter']);
        $this->assertFalse(Attribute::query()->where('name_en', 'Terrace')->exists());

        // تعديل واحدة موجودة وفضلت chip: مش بتتحسب ضد نفسها
        $wifi = $this->attribute('wifi');
        $this->assertTrue($wifi->is_quick_filter);

        Livewire::test(ManageAttributes::class)
            ->callAction(TestAction::make('edit')->table($wifi), $this->formData([
                'name_ar' => 'واي فاي', 'name_en' => 'Wi-Fi', 'group' => 'services', 'is_quick_filter' => true,
            ]))
            ->assertHasNoActionErrors();

        // وبعد ما تشيل واحدة تقدر تضيف الجديدة
        $this->attribute('shisha')->update(['is_quick_filter' => false]);

        Livewire::test(ManageAttributes::class)
            ->callAction('create', $this->formData(['is_quick_filter' => true]))
            ->assertHasNoActionErrors();
        $this->assertTrue($this->attribute('terrace')->is_quick_filter);
    }

    public function test_a_quick_filter_must_be_filterable_and_active(): void
    {
        $this->asAdmin();
        $this->attribute('shisha')->update(['is_quick_filter' => false]);

        Livewire::test(ManageAttributes::class)
            ->callAction('create', $this->formData(['is_quick_filter' => true, 'is_filterable' => false]))
            ->assertHasActionErrors(['is_quick_filter']);

        Livewire::test(ManageAttributes::class)
            ->callAction('create', $this->formData(['is_quick_filter' => true, 'is_active' => false]))
            ->assertHasActionErrors(['is_quick_filter']);
    }

    public function test_hiding_a_quick_filter_attribute_drops_it_from_the_chips(): void
    {
        $this->asAdmin();
        $wifi = $this->attribute('wifi');
        $this->assertTrue($wifi->is_quick_filter);
        $this->assertContains('wifi', $this->apiSlugs());

        Livewire::test(ManageAttributes::class)
            ->call('updateTableColumnState', 'is_active', (string) $wifi->id, false);

        $wifi->refresh();
        $this->assertFalse($wifi->is_active);
        $this->assertFalse($wifi->is_quick_filter);
        $this->assertNotContains('wifi', $this->apiSlugs());
    }

    public function test_reordering_by_drag_changes_the_api_order(): void
    {
        $this->asAdmin();
        $before = $this->apiSlugs(); // بيملى الـ cache

        $ids = Attribute::query()->orderBy('sort_order')->pluck('id')->all();
        // أول خاصية (quiet) تروح آخر القائمة
        Livewire::test(ManageAttributes::class)->call('reorderTable', [...array_slice($ids, 1), $ids[0]]);

        $after = $this->apiSlugs();
        $this->assertSame('quiet', $before[0]);
        $this->assertSame($before[1], $after[0]);
        // quiet لسه في مجموعتها (الجو)، بس آخر واحدة فيها
        $this->assertSame('quiet', $after[array_search('live-music', $after, true) + 1]);
    }

    public function test_a_used_attribute_cannot_be_deleted_but_an_unused_one_can(): void
    {
        $this->seed(DemoPlaceSeeder::class);
        $admin = $this->asAdmin();

        $used = $this->attribute('wifi');
        $unused = Attribute::query()->create($this->formData() + ['slug' => 'unused-attr']);
        $this->assertTrue($used->isInUse());
        $this->assertFalse($unused->isInUse());
        $this->assertTrue($admin->cannot('delete', $used));
        $this->assertTrue($admin->can('delete', $unused));

        $component = Livewire::test(ManageAttributes::class)
            ->assertActionHidden(TestAction::make('delete')->table($used))
            ->assertActionVisible(TestAction::make('delete')->table($unused));

        $component->callAction(TestAction::make('delete')->table($unused));

        $this->assertTrue(Attribute::query()->whereKey($used->id)->exists());
        $this->assertFalse(Attribute::query()->whereKey($unused->id)->exists());
    }

    public function test_moderator_can_view_but_not_change_anything(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('moderator'));
        $wifi = $this->attribute('wifi');

        Livewire::test(ManageAttributes::class)
            // الجدول بيعرض 10 صفوف في الصفحة، فبنفحص أول 5 بس
            ->assertCanSeeTableRecords(Attribute::query()->orderBy('sort_order')->limit(5)->get(), inOrder: true)
            ->assertActionHidden('create')
            ->assertActionHidden(TestAction::make('edit')->table($wifi))
            ->call('updateTableColumnState', 'is_active', (string) $wifi->id, false);

        $this->assertTrue($wifi->fresh()->is_active);
    }

    public function test_changes_are_recorded_in_the_activity_log_with_the_causer(): void
    {
        $admin = $this->asAdmin();

        Livewire::test(ManageAttributes::class)
            ->callAction(TestAction::make('edit')->table($this->attribute('wifi')), $this->formData([
                'name_ar' => 'إنترنت سريع', 'name_en' => 'Fast Wi-Fi', 'group' => 'services',
            ]))
            ->assertHasNoActionErrors();

        $log = Activity::query()->where('log_name', 'attributes')->latest('id')->firstOrFail();

        $this->assertSame('updated', $log->event);
        $this->assertSame($admin->id, $log->causer_id);
        $this->assertSame('إنترنت سريع', $log->properties['attributes']['name_ar']);
    }
}
