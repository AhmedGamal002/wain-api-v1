<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\Places\Pages\ListPlaces;
use App\Filament\Resources\Places\Pages\ViewPlace;
use App\Filament\Resources\Places\PlaceResource;
use App\Filament\Resources\Places\RelationManagers\ActivitiesRelationManager;
use App\Models\Place;
use App\Models\User;
use App\Support\PlaceTransitions;
use Database\Seeders\AttributeSeeder;
use Database\Seeders\CategorySeeder;
use Database\Seeders\CityAreaSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * صفحة عرض المكان وسجل التغييرات (03 القسم 4.7): المحذوف بيتفتح فيها، والسجل بالقيمة القديمة والجديدة
 * ومين عمله، وما بيتمسحش مع الحذف.
 */
class PlaceViewPageTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleSeeder::class, SettingSeeder::class, CategorySeeder::class, CityAreaSeeder::class, AttributeSeeder::class]);
        Filament::setCurrentPanel('admin');
        $this->admin = User::factory()->create(['name' => 'جمال'])->assignRole('admin');
        $this->actingAs($this->admin);
    }

    public function test_the_view_page_opens_for_the_team_and_shows_the_place(): void
    {
        $place = Place::factory()->published()->create(['name_ar' => 'كافيه الأمل']);

        $this->get("/admin/places/{$place->id}")->assertOk()->assertSee('كافيه الأمل');

        $this->actingAs(User::factory()->create()->assignRole('moderator'));
        $this->get("/admin/places/{$place->id}")->assertOk();

        $this->actingAs(User::factory()->create()->assignRole('owner'));
        $this->get("/admin/places/{$place->id}")->assertForbidden();
    }

    public function test_a_deleted_place_opens_in_the_view_page_with_a_restore_button(): void
    {
        $place = Place::factory()->published()->create();
        $place->delete();

        $this->get("/admin/places/{$place->id}")->assertOk();

        Livewire::test(ViewPlace::class, ['record' => $place->getKey()])
            ->assertActionVisible('restore')
            ->assertActionHidden('hide')
            ->callAction('restore')
            ->assertHasNoActionErrors();

        $place->refresh();
        $this->assertFalse($place->trashed());
        $this->assertSame(Place::STATUS_HIDDEN, $place->status);
    }

    public function test_a_deleted_row_in_the_list_links_to_the_view_page(): void
    {
        $place = Place::factory()->create();
        $place->delete();

        Livewire::test(ListPlaces::class)
            ->loadTable()
            ->filterTable('trashed', true)
            ->assertCanSeeTableRecords([$place])
            ->assertActionHasUrl(TestAction::make('view')->table($place), PlaceResource::getUrl('view', ['record' => $place]));
    }

    public function test_the_log_lists_changes_with_who_did_them_and_old_and_new_values(): void
    {
        $place = Place::factory()->published()->create();
        (new PlaceTransitions)->hide($place, $this->admin, 'complaint', 'اتشكى');

        $log = Activity::query()
            ->where('subject_id', $place->id)
            ->where('log_name', Place::LOG_NAME)
            ->where('event', 'updated')
            ->latest('id')
            ->firstOrFail();

        Livewire::test(ActivitiesRelationManager::class, ['ownerRecord' => $place, 'pageClass' => ViewPlace::class])
            ->assertCanSeeTableRecords([$log])
            ->assertSee('جمال')
            ->assertSee('تغيير حالة')
            ->assertSee('الحالة: منشور ← مخفي')
            ->assertSee('شكوى: اتشكى');
    }

    public function test_the_log_survives_deleting_the_place(): void
    {
        $place = Place::factory()->published()->create();
        $place->update(['phone' => '+201001234567']);
        $place->delete();

        $this->assertGreaterThan(0, Activity::query()->where('subject_id', $place->id)->count());

        $manager = Livewire::test(ActivitiesRelationManager::class, [
            'ownerRecord' => Place::withTrashed()->findOrFail($place->id),
            'pageClass' => ViewPlace::class,
        ]);

        $manager->assertSee('التليفون');
    }
}
