<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\Places\Pages\CreatePlace;
use App\Filament\Resources\Places\Pages\EditPlace;
use App\Models\Place;
use App\Models\PlacePhoto;
use App\Models\User;
use Database\Seeders\AttributeSeeder;
use Database\Seeders\CategorySeeder;
use Database\Seeders\CityAreaSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * تاب «الصور» في صفحة التعديل (03 القسم 4.5): الرفع من الـ modals، والغلاف والترتيب والحذف من الـ Livewire
 * methods، والغلاف شرط في الـ checklist. معالجة الصور نفسها في `PlacePhotosTest`.
 */
class PlacePhotosPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleSeeder::class, SettingSeeder::class, CategorySeeder::class, CityAreaSeeder::class, AttributeSeeder::class]);
        Filament::setCurrentPanel('admin');
        Storage::fake('local');
        Storage::fake('public');
        $this->actingAs(User::factory()->create()->assignRole('admin'));
    }

    private function photoAction(string $name): TestAction
    {
        return TestAction::make($name)->schemaComponent('place-tabs.photos.photo-actions', schema: 'form');
    }

    private function edit(Place $place)
    {
        return Livewire::test(EditPlace::class, ['record' => $place->getKey()]);
    }

    public function test_the_create_page_asks_to_save_first(): void
    {
        Livewire::test(CreatePlace::class)
            ->assertSee('احفظ المكان كمسودة الأول')
            ->assertActionDoesNotExist($this->photoAction('uploadGallery'));
    }

    public function test_uploading_a_cover_from_the_modal_makes_it_the_cover_and_ticks_the_checklist(): void
    {
        $place = Place::factory()->create();

        $this->edit($place)
            ->callAction($this->photoAction('uploadCover'), ['file' => UploadedFile::fake()->image('cover.jpg', 1800, 1200)])
            ->assertHasNoActionErrors();

        $place->refresh();
        $this->assertNotNull($place->cover_photo_id);
        $this->assertTrue($place->coverPhoto->isReady());
        $this->assertSame('gallery', $place->coverPhoto->type);
    }

    public function test_uploading_gallery_and_menu_photos(): void
    {
        $place = Place::factory()->create();

        $this->edit($place)
            ->callAction($this->photoAction('uploadGallery'), ['files' => [
                UploadedFile::fake()->image('a.jpg', 1200, 800),
                UploadedFile::fake()->image('b.jpg', 1200, 800),
            ]])
            ->callAction($this->photoAction('uploadMenu'), ['files' => [UploadedFile::fake()->image('menu.jpg', 600, 900)]]);

        $this->assertSame(2, $place->photos()->where('type', 'gallery')->count());
        $this->assertSame(1, $place->photos()->where('type', 'menu')->count());
        $this->assertNull($place->refresh()->cover_photo_id);
    }

    public function test_a_rejected_file_is_reported_and_the_good_ones_still_upload(): void
    {
        $place = Place::factory()->create();

        $this->edit($place)
            ->callAction($this->photoAction('uploadGallery'), ['files' => [
                UploadedFile::fake()->image('good.jpg', 1200, 800),
                UploadedFile::fake()->image('narrow.jpg', 400, 800),
            ]])
            ->assertNotified('بعض الصور اترفضت');

        $this->assertSame(1, $place->photos()->count());
    }

    public function test_make_cover_delete_and_move_work_from_the_tab(): void
    {
        $place = Place::factory()->create();
        $page = $this->edit($place)
            ->callAction($this->photoAction('uploadGallery'), ['files' => [
                UploadedFile::fake()->image('a.jpg', 1200, 800),
                UploadedFile::fake()->image('b.jpg', 1200, 800),
            ]]);

        [$a, $b] = $place->photos()->get()->all();

        $page->call('makePhotoCover', $b->id);
        $this->assertSame($b->id, $place->refresh()->cover_photo_id);

        $page->call('movePhoto', $b->id, -1);
        $this->assertSame([$b->id, $a->id], $place->photos()->pluck('id')->all());

        $page->call('deletePhoto', $a->id);
        $this->assertNull(PlacePhoto::find($a->id));
    }

    public function test_the_cover_of_a_published_place_can_not_be_deleted_from_the_tab(): void
    {
        $place = Place::factory()->published()->create();
        $coverId = $place->cover_photo_id;

        $this->edit($place)->call('deletePhoto', $coverId)->assertNotified();

        $this->assertSame($coverId, $place->refresh()->cover_photo_id);
        $this->assertNotNull(PlacePhoto::find($coverId));
    }

    public function test_a_moderator_can_manage_photos_and_an_owner_can_not_reach_the_page(): void
    {
        $place = Place::factory()->create();
        $this->actingAs(User::factory()->create()->assignRole('moderator'));

        $this->edit($place)
            ->callAction($this->photoAction('uploadGallery'), ['files' => [UploadedFile::fake()->image('a.jpg', 1200, 800)]]);
        $this->assertSame(1, $place->photos()->count());

        $this->actingAs(User::factory()->create()->assignRole('owner'));
        $this->get("/admin/places/{$place->id}/edit")->assertForbidden();
    }

    public function test_the_view_page_shows_the_photos_read_only(): void
    {
        $place = Place::factory()->published()->create();

        $this->get("/admin/places/{$place->id}")
            ->assertOk()
            ->assertSee('صورة الغلاف')
            ->assertDontSee('deletePhoto');
    }
}
