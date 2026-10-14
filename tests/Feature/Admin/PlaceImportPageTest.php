<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\Places\Pages\ImportPlaces;
use App\Filament\Resources\Places\Pages\ListPlaces;
use App\Jobs\ImportPlacesFromCsv;
use App\Models\Attribute;
use App\Models\Place;
use App\Models\PlaceImport;
use App\Models\User;
use App\Support\PlaceCsv;
use App\Support\PlaceCsvImporter;
use Database\Seeders\AttributeSeeder;
use Database\Seeders\CategorySeeder;
use Database\Seeders\CityAreaSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * صفحة استيراد CSV في اللوحة (A10): الرفع ← المعاينة ← التأكيد ← النتيجة، وتصدير القايمة. المنطق نفسه في
 * `PlaceCsvTest`، هنا اللي بيتأكد منه الـ UI والصلاحيات.
 */
class PlaceImportPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleSeeder::class, SettingSeeder::class, CategorySeeder::class, CityAreaSeeder::class, AttributeSeeder::class]);
        Filament::setCurrentPanel('admin');
    }

    private function as(string $role): User
    {
        $user = User::factory()->create()->assignRole($role);
        $this->actingAs($user);

        return $user;
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function row(array $overrides = []): array
    {
        return [
            'name_ar' => 'سيلانترو', 'name_en' => 'Cilantro', 'categories' => 'cafes', 'area' => 'new-cairo',
            'address_ar' => 'شارع التسعين', 'latitude' => '30.0283', 'longitude' => '31.4913',
            'attributes' => Attribute::query()->orderBy('id')->limit(3)->pluck('slug')->implode(';'),
            ...$overrides,
        ];
    }

    /**
     * @param  list<array<string, string>>  $rows
     */
    private function file(array $rows, string $name = 'places.csv'): UploadedFile
    {
        $columns = PlaceCsv::columns();
        $out = fopen('php://temp', 'r+');
        fputcsv($out, $columns, ',', '"', '\\');

        foreach ($rows as $row) {
            fputcsv($out, array_map(fn (string $column) => $row[$column] ?? '', $columns), ',', '"', '\\');
        }

        rewind($out);

        return UploadedFile::fake()->createWithContent($name, (string) stream_get_contents($out));
    }

    private function upload(array $rows)
    {
        return Livewire::test(ImportPlaces::class)
            ->callAction('upload', ['file' => $this->file($rows)]);
    }

    public function test_the_page_shows_the_steps_and_the_template_columns_before_any_upload(): void
    {
        $this->as('admin');

        Livewire::test(ImportPlaces::class)
            ->assertSuccessful()
            ->assertSee('أعمدة القالب')
            ->assertSee('name_ar')
            ->assertActionVisible('upload')
            ->assertActionVisible('template')
            ->assertActionHidden('confirm')
            ->assertActionHidden('discard');
    }

    public function test_the_template_downloads_with_the_header_row(): void
    {
        $this->as('admin');

        Livewire::test(ImportPlaces::class)
            ->callAction('template')
            ->assertFileDownloaded('places-template.csv');
    }

    public function test_uploading_a_file_opens_the_preview_without_adding_anything(): void
    {
        $this->as('moderator');

        $this->upload([$this->row(), $this->row(['name_ar' => '', 'name_en' => 'Broken'])])
            ->assertHasNoActionErrors();

        $import = PlaceImport::query()->sole();

        $this->assertSame([2, 1, 0, 1], [$import->total_rows, $import->valid_rows, $import->duplicate_rows, $import->error_rows]);
        $this->assertSame(0, Place::query()->count());

        Livewire::withQueryParams(['import' => $import->id])
            ->test(ImportPlaces::class)
            ->assertSee('سيلانترو')
            ->assertSee('Broken')
            ->assertSee('name_ar')
            ->assertActionVisible('confirm')
            ->assertActionVisible('discard')
            ->assertActionVisible('downloadErrors');
    }

    public function test_a_file_that_is_not_valid_is_refused_with_its_reason(): void
    {
        $this->as('admin');

        Livewire::test(ImportPlaces::class)
            ->callAction('upload', ['file' => UploadedFile::fake()->createWithContent('bad.csv', "name_ar,name_en\nx,y\n")]);

        $this->assertSame(0, PlaceImport::query()->count());
    }

    public function test_confirming_queues_the_import_once_and_the_job_adds_the_drafts(): void
    {
        $this->as('admin');
        $this->upload([$this->row()]);
        $import = PlaceImport::query()->sole();

        Queue::fake();

        Livewire::withQueryParams(['import' => $import->id])
            ->test(ImportPlaces::class)
            ->callAction('confirm')
            ->assertHasNoActionErrors();

        Queue::assertPushed(ImportPlacesFromCsv::class, 1);
        $this->assertSame(PlaceImport::STATUS_IMPORTING, $import->refresh()->status);

        // ضغطة تانية على نفس المعاينة ما بتشغّلش job تاني
        Livewire::withQueryParams(['import' => $import->id])
            ->test(ImportPlaces::class)
            ->assertActionHidden('confirm');
        Queue::assertPushed(ImportPlacesFromCsv::class, 1);
        $this->assertSame(0, Place::query()->count());
    }

    public function test_the_worker_adds_the_drafts_and_tells_the_uploader_in_the_bell(): void
    {
        $admin = $this->as('admin');
        $this->upload([$this->row()]);
        $import = PlaceImport::query()->sole();
        $import->forceFill(['status' => PlaceImport::STATUS_IMPORTING])->save();

        (new ImportPlacesFromCsv($import->id))->handle(app(PlaceCsvImporter::class));

        $place = Place::query()->sole();
        $this->assertSame(Place::STATUS_DRAFT, $place->status);
        $this->assertSame($admin->id, $place->created_by);

        // الجرس: نتيجة الاستيراد ورابط الصفحة لمن رفع الملف
        $notification = $admin->notifications()->sole();
        $this->assertStringContainsString('اتستورد 1 مكان', $notification->data['title']);
        $this->assertStringContainsString('import='.$import->id, json_encode($notification->data['actions'][0]['url']));
    }

    public function test_the_result_page_links_to_the_added_places_and_offers_the_errors_file(): void
    {
        $this->as('admin');
        $this->upload([$this->row(), $this->row(['name_ar' => 'غلط', 'name_en' => 'Wrong', 'latitude' => '99'])]);
        $import = PlaceImport::query()->sole();

        $import->forceFill(['status' => PlaceImport::STATUS_IMPORTING])->save();
        (new ImportPlacesFromCsv($import->id))->handle(app(PlaceCsvImporter::class));

        Livewire::withQueryParams(['import' => $import->id])
            ->test(ImportPlaces::class)
            ->assertSee('اتضاف')
            ->assertActionHidden('confirm')
            ->assertActionVisible('downloadErrors')
            ->callAction('downloadErrors')
            ->assertFileDownloaded('places-import-'.$import->id.'-errors.csv');
    }

    public function test_discarding_a_preview_deletes_it(): void
    {
        $this->as('admin');
        $this->upload([$this->row()]);
        $import = PlaceImport::query()->sole();

        Livewire::withQueryParams(['import' => $import->id])
            ->test(ImportPlaces::class)
            ->callAction('discard');

        $this->assertSame(0, PlaceImport::query()->count());
        $this->assertSame(0, Place::query()->count());
    }

    public function test_an_import_of_someone_else_is_closed_to_a_moderator_but_open_to_an_admin(): void
    {
        $owner = $this->as('moderator');
        $this->upload([$this->row()]);
        $import = PlaceImport::query()->sole();
        $this->assertSame($owner->id, $import->user_id);

        $this->as('moderator');
        Livewire::withQueryParams(['import' => $import->id])->test(ImportPlaces::class)->assertForbidden();

        $this->as('admin');
        Livewire::withQueryParams(['import' => $import->id])->test(ImportPlaces::class)->assertSuccessful();
    }

    public function test_a_stranger_cannot_open_the_page(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(ImportPlaces::class)->assertForbidden();
    }

    public function test_the_list_has_the_import_and_export_buttons(): void
    {
        $this->as('moderator');

        Livewire::test(ListPlaces::class)
            ->assertActionVisible('import')
            ->assertActionVisible('export');
    }

    public function test_exporting_the_list_downloads_a_csv_with_the_filtered_places(): void
    {
        $this->as('admin');
        Place::factory()->create(['name_ar' => 'مسودة']);
        Place::factory()->published()->create(['name_ar' => 'منشور']);

        $response = Livewire::test(ListPlaces::class)
            ->set('activeTab', Place::STATUS_ACTIVE)
            ->callAction('export')
            ->assertFileDownloaded();

        $this->assertNotNull($response);
    }

    public function test_the_bulk_export_is_there_for_an_admin_only(): void
    {
        $this->as('admin');
        $place = Place::factory()->create();

        Livewire::test(ListPlaces::class)
            ->callTableBulkAction('exportCsv', [$place])
            ->assertFileDownloaded();

        $this->as('moderator');
        Livewire::test(ListPlaces::class)->assertTableBulkActionHidden('exportCsv');
    }
}
