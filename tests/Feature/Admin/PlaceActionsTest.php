<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\Places\Pages\CreatePlace;
use App\Filament\Resources\Places\Pages\EditPlace;
use App\Filament\Resources\Places\Pages\ListPlaces;
use App\Models\Place;
use App\Models\User;
use Database\Seeders\AttributeSeeder;
use Database\Seeders\CategorySeeder;
use Database\Seeders\CityAreaSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * أزرار المكان وmodals-ها في القائمة وصفحة التعديل (03 القسم 4.2 و4.6). القواعد نفسها في
 * `PlaceTransitionsTest`، هنا اللي بيتأكد منه الـ UI: مين بيشوف إيه، والسبب إلزامي، والرسالة بتسمّي الناقص.
 */
class PlaceActionsTest extends TestCase
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

    private function list()
    {
        return Livewire::test(ListPlaces::class);
    }

    private function row(Place $place): TestAction
    {
        return TestAction::make('hide')->table($place);
    }

    // ───────────────────────── ظهور الأزرار حسب الحالة والدور ─────────────────────────

    public function test_row_actions_follow_the_status_for_an_admin(): void
    {
        $this->asAdmin();
        $draft = Place::factory()->complete()->create();
        $pending = Place::factory()->complete()->status(Place::STATUS_PENDING)->create();
        $active = Place::factory()->published()->create();
        $hidden = Place::factory()->published()->status(Place::STATUS_HIDDEN)->create();
        $closed = Place::factory()->published()->status(Place::STATUS_CLOSED)->create();

        $list = $this->list()->loadTable();

        $list->assertActionVisible(TestAction::make('submit')->table($draft))
            ->assertActionVisible(TestAction::make('publish')->table($draft))
            ->assertActionVisible(TestAction::make('publish')->table($pending))
            ->assertActionVisible(TestAction::make('reject')->table($pending))
            ->assertActionVisible(TestAction::make('hide')->table($active))
            ->assertActionVisible(TestAction::make('tier')->table($active))
            ->assertActionVisible(TestAction::make('verify')->table($active))
            ->assertActionVisible(TestAction::make('show')->table($hidden))
            ->assertActionVisible(TestAction::make('close')->table($hidden))
            ->assertActionVisible(TestAction::make('reopen')->table($closed))
            ->assertActionVisible(TestAction::make('delete')->table($draft))
            ->assertActionVisible(TestAction::make('duplicate')->table($draft));

        // الحالة الغلط: الزرار مش بيظهر أصلاً
        $list->assertActionHidden(TestAction::make('hide')->table($draft))
            ->assertActionHidden(TestAction::make('reject')->table($active))
            ->assertActionHidden(TestAction::make('show')->table($active))
            ->assertActionHidden(TestAction::make('submit')->table($pending))
            ->assertActionHidden(TestAction::make('tier')->table($hidden))
            ->assertActionHidden(TestAction::make('close')->table($closed));
    }

    public function test_moderator_only_sees_edit_submit_verify_and_duplicate(): void
    {
        $this->asModerator();
        $draft = Place::factory()->complete()->create();
        $active = Place::factory()->published()->create();

        $list = $this->list()->loadTable();

        $list->assertActionVisible(TestAction::make('submit')->table($draft))
            ->assertActionVisible(TestAction::make('verify')->table($active))
            ->assertActionVisible(TestAction::make('duplicate')->table($active));

        foreach (['publish' => $draft, 'hide' => $active, 'tier' => $active, 'close' => $active, 'delete' => $active] as $name => $place) {
            $list->assertActionHidden(TestAction::make($name)->table($place));
        }
    }

    public function test_a_forbidden_action_can_not_be_mounted_or_run(): void
    {
        $this->asModerator();
        $active = Place::factory()->published()->create();

        $list = $this->list()->loadTable();
        $list->assertActionHidden(TestAction::make('hide')->table($active));

        // الطلب المباشر على الـ Livewire (من غير الزرار): الـ action مخفي فمش بيتنفّذ
        $list->call('mountAction', 'hide', [], ['table' => true, 'recordKey' => (string) $active->getKey()]);

        $this->assertSame(Place::STATUS_ACTIVE, $active->refresh()->status);
    }

    public function test_only_a_trashed_place_shows_restore(): void
    {
        $this->asAdmin();
        $place = Place::factory()->published()->create();
        $place->delete();

        $this->list()
            ->loadTable()
            ->filterTable('trashed', true)
            ->assertActionVisible(TestAction::make('restore')->table($place))
            ->assertActionHidden(TestAction::make('hide')->table($place))
            ->assertActionHidden(TestAction::make('delete')->table($place));
    }

    // ───────────────────────── الـ modals ─────────────────────────

    public function test_hide_needs_a_reason(): void
    {
        $this->asAdmin();
        $place = Place::factory()->published()->create();

        $this->list()->loadTable()
            ->callAction($this->row($place), ['reason' => null])
            ->assertHasActionErrors(['reason' => 'required']);

        $this->assertSame(Place::STATUS_ACTIVE, $place->refresh()->status);
    }

    public function test_hide_with_the_other_reason_needs_details(): void
    {
        $this->asAdmin();
        $place = Place::factory()->published()->create();

        $this->list()->loadTable()
            ->callAction($this->row($place), ['reason' => 'other', 'details' => ''])
            ->assertHasActionErrors(['details' => 'required']);

        $this->assertSame(Place::STATUS_ACTIVE, $place->refresh()->status);
    }

    public function test_hide_hides_the_place_with_the_reason(): void
    {
        $this->asAdmin();
        $place = Place::factory()->published()->create();

        $this->list()->loadTable()
            ->callAction($this->row($place), ['reason' => 'complaint', 'details' => 'اتشكى من الزباين'])
            ->assertHasNoActionErrors();

        $place->refresh();
        $this->assertSame(Place::STATUS_HIDDEN, $place->status);
        $this->assertSame('شكوى: اتشكى من الزباين', $place->status_reason);
    }

    public function test_reject_needs_a_reason_and_keeps_it(): void
    {
        $this->asAdmin();
        $place = Place::factory()->complete()->status(Place::STATUS_PENDING)->create();
        $action = TestAction::make('reject')->table($place);

        $this->list()->loadTable()
            ->callAction($action, ['reason' => null])
            ->assertHasActionErrors(['reason' => 'required']);

        $this->list()->loadTable()
            ->callAction($action, ['reason' => 'missing_data', 'notes' => 'ناقص صور'])
            ->assertHasNoActionErrors();

        $place->refresh();
        $this->assertSame(Place::STATUS_REJECTED, $place->status);
        $this->assertSame('بيانات ناقصة: ناقص صور', $place->status_reason);
    }

    public function test_publish_of_an_incomplete_place_is_refused_with_the_missing_names(): void
    {
        $this->asAdmin();
        $place = Place::factory()->status(Place::STATUS_PENDING)->create();

        $this->list()->loadTable()
            ->callAction(TestAction::make('publish')->table($place))
            ->assertNotified();

        $this->assertSame(Place::STATUS_PENDING, $place->refresh()->status);
    }

    public function test_publish_publishes_a_complete_place(): void
    {
        $this->asAdmin();
        $place = Place::factory()->complete()->status(Place::STATUS_PENDING)->create();

        $this->list()->loadTable()
            ->callAction(TestAction::make('publish')->table($place))
            ->assertHasNoActionErrors();

        $this->assertSame(Place::STATUS_ACTIVE, $place->refresh()->status);
    }

    public function test_close_needs_a_date_that_is_not_in_the_future(): void
    {
        $this->asAdmin();
        $place = Place::factory()->published()->create();
        $action = TestAction::make('close')->table($place);

        $this->list()->loadTable()
            ->callAction($action, ['closed_at' => null])
            ->assertHasActionErrors(['closed_at' => 'required']);

        $this->list()->loadTable()
            ->callAction($action, ['closed_at' => now('Africa/Cairo')->addDays(3)->toDateString()])
            ->assertHasActionErrors(['closed_at']);

        $this->assertSame(Place::STATUS_ACTIVE, $place->refresh()->status);

        $this->list()->loadTable()
            ->callAction($action, ['closed_at' => now('Africa/Cairo')->toDateString(), 'note' => 'اتقفل'])
            ->assertHasNoActionErrors();

        $this->assertSame(Place::STATUS_CLOSED, $place->refresh()->status);
    }

    public function test_tier_modal_saves_the_tier_with_dates(): void
    {
        $this->asAdmin();
        $place = Place::factory()->published()->create();

        $this->list()->loadTable()
            ->callAction(TestAction::make('tier')->table($place), [
                'tier' => 'premium',
                'from' => '2026-12-01',
                'to' => '2026-12-31',
                'note' => 'INV-7',
            ])
            ->assertHasNoActionErrors();

        $place->refresh();
        $this->assertSame('premium', $place->listing_tier);
        $this->assertSame(1, $place->tier_rank);
    }

    public function test_tier_modal_needs_dates_for_a_paid_tier(): void
    {
        $this->asAdmin();
        $place = Place::factory()->published()->create();

        $this->list()->loadTable()
            ->callAction(TestAction::make('tier')->table($place), ['tier' => 'featured', 'from' => null, 'to' => null])
            ->assertHasActionErrors(['from' => 'required', 'to' => 'required']);

        $this->assertSame('free', $place->refresh()->listing_tier);
    }

    public function test_deleting_a_published_place_needs_its_name(): void
    {
        $this->asAdmin();
        $place = Place::factory()->published()->create(['name_ar' => 'كافيه الأمل']);
        $action = TestAction::make('delete')->table($place);

        $this->list()->loadTable()
            ->callAction($action, ['confirm_name' => 'اسم تاني'])
            ->assertHasActionErrors(['confirm_name']);
        $this->assertFalse($place->refresh()->trashed());

        $this->list()->loadTable()
            ->callAction($action, ['confirm_name' => 'كافيه الأمل'])
            ->assertHasNoActionErrors();
        $this->assertTrue($place->refresh()->trashed());
    }

    public function test_deleting_a_draft_needs_no_confirmation_name(): void
    {
        $this->asAdmin();
        $place = Place::factory()->create();

        $this->list()->loadTable()
            ->callAction(TestAction::make('delete')->table($place))
            ->assertHasNoActionErrors();

        $this->assertTrue($place->refresh()->trashed());
    }

    public function test_restore_brings_a_deleted_place_back_hidden(): void
    {
        $this->asAdmin();
        $place = Place::factory()->published()->create();
        $place->delete();

        $this->list()->loadTable()
            ->filterTable('trashed', true)
            ->callAction(TestAction::make('restore')->table($place))
            ->assertHasNoActionErrors();

        $place->refresh();
        $this->assertFalse($place->trashed());
        $this->assertSame(Place::STATUS_HIDDEN, $place->status);
    }

    // ───────────────────────── جماعي ─────────────────────────

    public function test_bulk_publish_skips_the_incomplete(): void
    {
        $this->asAdmin();
        $good = Place::factory()->complete()->status(Place::STATUS_PENDING)->count(2)->create();
        $bad = Place::factory()->status(Place::STATUS_PENDING)->create();

        $this->list()->loadTable()
            ->callTableBulkAction('bulkPublish', $good->push($bad))
            ->assertNotified();

        $this->assertSame(2, Place::query()->where('status', Place::STATUS_ACTIVE)->count());
        $this->assertSame(Place::STATUS_PENDING, $bad->refresh()->status);
    }

    public function test_bulk_hide_uses_one_reason_for_all_and_skips_non_published(): void
    {
        $this->asAdmin();
        $published = Place::factory()->published()->count(2)->create();
        $draft = Place::factory()->create();

        $this->list()->loadTable()
            ->callTableBulkAction('bulkHide', $published->push($draft), ['reason' => 'renovation']);

        $this->assertSame(2, Place::query()->where('status', Place::STATUS_HIDDEN)->count());
        $this->assertSame(Place::STATUS_DRAFT, $draft->refresh()->status);
    }

    public function test_bulk_actions_are_not_available_to_a_moderator(): void
    {
        $this->asModerator();
        $places = Place::factory()->published()->count(2)->create();

        $this->list()->loadTable()
            ->assertTableBulkActionHidden('bulkPublish')
            ->assertTableBulkActionHidden('bulkDelete');

        $this->assertSame(2, $places->count());
    }

    // ───────────────────────── صفحة التعديل ─────────────────────────

    public function test_the_edit_page_header_has_the_same_actions(): void
    {
        $this->asAdmin();
        $place = Place::factory()->published()->create();

        Livewire::test(EditPlace::class, ['record' => $place->getKey()])
            ->assertActionVisible('hide')
            ->assertActionVisible('tier')
            ->assertActionVisible('close')
            ->assertActionHidden('publish')
            ->callAction('hide', ['reason' => 'needs_review'])
            ->assertHasNoActionErrors();

        $this->assertSame(Place::STATUS_HIDDEN, $place->refresh()->status);
    }

    public function test_the_edit_page_does_not_offer_status_actions_to_a_moderator(): void
    {
        $this->asModerator();
        $place = Place::factory()->published()->create();

        Livewire::test(EditPlace::class, ['record' => $place->getKey()])
            ->assertActionHidden('hide')
            ->assertActionHidden('delete')
            ->assertActionVisible('verify');
    }

    // ───────────────────────── تكرار ─────────────────────────

    public function test_duplicate_prefills_the_create_form_without_photos_location_or_phone(): void
    {
        $this->asAdmin();
        $source = Place::factory()->published()->create([
            'phone' => '+201001234567',
            'website' => 'https://example.com',
            'instagram' => 'cilantro',
        ]);

        $page = Livewire::withQueryParams(['duplicate' => $source->getKey()])->test(CreatePlace::class);

        $page->assertSchemaStateSet(fn (array $state) => $state['name_ar'] === $source->name_ar
            && $state['website'] === 'https://example.com'
            && $state['instagram'] === 'cilantro'
            && $state['city_id'] === $source->city_id
            && $state['primary_category_id'] === $source->primary_category_id
            && empty($state['phone'])
            && empty($state['latitude'])
            && empty($state['longitude'])
            && empty($state['area_id'])
            && empty($state['address_ar']));

        $this->assertSame(1, Place::query()->count(), 'التكرار ما بيحفظش لوحده');
    }
}
