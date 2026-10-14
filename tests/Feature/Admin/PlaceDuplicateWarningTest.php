<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\Places\Pages\CreatePlace;
use App\Filament\Resources\Places\Pages\EditPlace;
use App\Models\Area;
use App\Models\Category;
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
 * تحذير التكرار وقت الحفظ (03 القسم 4.6): المسودة بتتوقف ويظهر modal فيه المكان الشبه، و«كمّل، ده مكان
 * مختلف» بيكمّل الحفظ. التطابق نفسه في `PlaceDuplicatesTest`.
 */
class PlaceDuplicateWarningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleSeeder::class, SettingSeeder::class, CategorySeeder::class, CityAreaSeeder::class, AttributeSeeder::class]);
        Filament::setCurrentPanel('admin');
        $this->actingAs(User::factory()->create()->assignRole('admin'));
    }

    /** @param  array<string, mixed>  $override */
    private function draftData(array $override = []): array
    {
        $category = Category::query()->orderBy('id')->firstOrFail();
        $area = Area::query()->orderBy('id')->firstOrFail();

        return array_merge([
            'name_ar' => 'سيلانترو',
            'name_en' => 'Cilantro',
            'categories' => [$category->id],
            'primary_category_id' => $category->id,
            'city_id' => $area->city_id,
            'area_id' => $area->id,
            'address_ar' => 'شارع التسعين، التجمع الخامس',
            'latitude' => 30.03,
            'longitude' => 31.47,
        ], $override);
    }

    private function existing(array $override = []): Place
    {
        return Place::factory()->create([
            'name_ar' => 'سيلانترو',
            'name_en' => 'Cilantro',
            'latitude' => 30.0305,
            'longitude' => 31.47,
            ...$override,
        ]);
    }

    public function test_saving_a_near_duplicate_stops_and_shows_the_existing_place(): void
    {
        $existing = $this->existing();

        Livewire::test(CreatePlace::class)
            ->fillForm($this->draftData())
            ->call('create')
            ->assertActionMounted('duplicateWarning')
            ->assertMountedActionModalSee(['في مكان شبه ده قريب', 'سيلانترو', 'افتح الموجود']);

        $this->assertSame(1, Place::query()->count());
        $this->assertSame($existing->id, Place::query()->value('id'));
    }

    public function test_confirming_saves_the_place_anyway(): void
    {
        $this->existing();

        Livewire::test(CreatePlace::class)
            ->fillForm($this->draftData())
            ->call('create')
            ->assertActionMounted('duplicateWarning')
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame(2, Place::query()->count());
        $this->assertSame(Place::STATUS_DRAFT, Place::query()->latest('id')->value('status'));
    }

    public function test_a_different_name_is_saved_without_a_warning(): void
    {
        $this->existing(['name_ar' => 'مطعم الشرق', 'name_en' => 'El Sharq']);

        Livewire::test(CreatePlace::class)
            ->fillForm($this->draftData())
            ->call('create')
            ->assertActionNotMounted();

        $this->assertSame(2, Place::query()->count());
    }

    public function test_editing_other_fields_of_a_place_with_a_neighbour_does_not_warn(): void
    {
        $this->existing();
        $place = $this->existing(['latitude' => 30.0302]);

        Livewire::test(EditPlace::class, ['record' => $place->getKey()])
            ->fillForm(['internal_notes' => 'ملاحظة جديدة'])
            ->call('save')
            ->assertActionNotMounted();

        $this->assertSame('ملاحظة جديدة', $place->refresh()->internal_notes);
    }

    public function test_moving_a_place_next_to_its_namesake_warns(): void
    {
        $this->existing();
        $far = $this->existing(['latitude' => 30.2, 'longitude' => 31.6]);

        Livewire::test(EditPlace::class, ['record' => $far->getKey()])
            ->fillForm(['latitude' => 30.0302, 'longitude' => 31.47])
            ->call('save')
            ->assertActionMounted('duplicateWarning');

        $this->assertEqualsWithDelta(30.2, $far->refresh()->latitude, 0.0001);
    }

    public function test_confirming_the_warning_on_an_existing_place_saves_the_move(): void
    {
        $this->existing();
        $far = $this->existing(['latitude' => 30.2, 'longitude' => 31.6]);

        Livewire::test(EditPlace::class, ['record' => $far->getKey()])
            ->fillForm(['latitude' => 30.0302, 'longitude' => 31.47])
            ->call('save')
            ->assertActionMounted('duplicateWarning')
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertEqualsWithDelta(30.0302, $far->refresh()->latitude, 0.0001);
    }

    public function test_the_warning_does_not_downgrade_the_intent_of_a_save_and_publish(): void
    {
        $this->existing();
        $complete = Place::factory()->complete()->create([
            'name_ar' => 'سيلانترو', 'name_en' => 'Cilantro', 'latitude' => 30.2, 'longitude' => 31.6,
        ]);

        Livewire::test(EditPlace::class, ['record' => $complete->getKey()])
            ->fillForm(['latitude' => 30.0302, 'longitude' => 31.47])
            ->callAction(TestAction::make('saveAndPublish')->schemaComponent('form-actions', schema: 'content'))
            ->assertActionMounted('duplicateWarning')
            ->callMountedAction();

        // «حفظ ونشر» بيكمّل بنفس الزرار بعد التأكيد: المكان بقى منشور، مش مسودة
        $this->assertSame(Place::STATUS_ACTIVE, $complete->refresh()->status);
    }
}
