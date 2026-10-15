<?php

namespace Tests\Feature;

use App\Models\Place;
use App\Models\PlacePhoto;
use App\Models\User;
use App\Support\PlacePhotoMigrator;
use App\Support\PlacePhotos;
use Database\Seeders\AttributeSeeder;
use Database\Seeders\CategorySeeder;
use Database\Seeders\CityAreaSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * نقل الصور لـ R2 (أسبوع 9، 03 القسم 4.5): `photos:migrate` بينسخ الأصل والنسخ من الـ disks القديمة للحالية من
 * غير ما الصفوف تتغيّر. الـ disks الأربعة مزيّفة (`old_*` القديمة و`new_*` اللي في `wain.photos`).
 */
class PlacePhotoMigrateTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleSeeder::class, SettingSeeder::class, CategorySeeder::class, CityAreaSeeder::class, AttributeSeeder::class]);

        foreach (['old_public', 'old_original', 'new_public', 'new_original'] as $disk) {
            Storage::fake($disk);
        }

        $this->admin = User::factory()->create()->assignRole('admin');
        $this->actingAs($this->admin);

        // الرفع بيحصل على القديم، وبعدين الإعداد بيتبدّل للجديد زي ما هيحصل في `.env`
        $this->useDisks('old_public', 'old_original');
    }

    private function useDisks(string $photos, string $originals): void
    {
        config(['wain.photos.disk' => $photos, 'wain.photos.original_disk' => $originals]);
    }

    private function upload(Place $place, string $kind = PlacePhotos::KIND_GALLERY): PlacePhoto
    {
        $this->useDisks('old_public', 'old_original');

        return (new PlacePhotos)->upload(
            $place,
            UploadedFile::fake()->image(uniqid('p').'.jpg', 2000, 1500),
            $kind,
            $this->admin,
        )->refresh();
    }

    private function migrate(array $options = []): array
    {
        $this->useDisks('new_public', 'new_original');

        return app(PlacePhotoMigrator::class)->run(
            fromDisk: 'old_public',
            fromOriginalDisk: 'old_original',
            dryRun: $options['dry_run'] ?? false,
            reprocess: $options['reprocess'] ?? false,
            deleteSource: $options['delete_source'] ?? false,
            placeId: $options['place'] ?? null,
        );
    }

    public function test_copies_the_original_and_the_three_variants_without_touching_the_rows(): void
    {
        $photo = $this->upload(Place::factory()->create());
        $before = $photo->only(['path', 'variants', 'status', 'width', 'height']);

        $stats = $this->migrate();

        $this->assertSame(1, $stats['originals_copied']);
        $this->assertSame(3, $stats['variants_copied']);
        $this->assertSame(0, $stats['reprocessed']);
        $this->assertSame([], $stats['failed']);

        Storage::disk('new_original')->assertExists($photo->path);
        foreach ($photo->variants as $path) {
            Storage::disk('new_public')->assertExists($path);
        }

        $this->assertSame($before, $photo->fresh()->only(['path', 'variants', 'status', 'width', 'height']));
        // القديم لسه موجود لحد ما يتطلب مسحه
        Storage::disk('old_public')->assertExists($photo->variants['thumb']);
        Storage::disk('old_original')->assertExists($photo->path);
    }

    public function test_the_copy_is_byte_identical_to_the_source(): void
    {
        $photo = $this->upload(Place::factory()->create());

        $this->migrate();

        $this->assertSame(
            Storage::disk('old_public')->get($photo->variants['large']),
            Storage::disk('new_public')->get($photo->variants['large']),
        );
        $this->assertSame(
            Storage::disk('old_original')->get($photo->path),
            Storage::disk('new_original')->get($photo->path),
        );
    }

    public function test_running_it_again_copies_nothing(): void
    {
        $this->upload(Place::factory()->create());

        $this->migrate();
        $second = $this->migrate();

        $this->assertSame(1, $second['photos']);
        $this->assertSame(1, $second['already']);
        $this->assertSame(0, $second['originals_copied'] + $second['variants_copied'] + $second['reprocessed']);
    }

    public function test_a_partial_copy_is_completed_on_the_next_run(): void
    {
        $photo = $this->upload(Place::factory()->create());
        $this->migrate();

        Storage::disk('new_public')->delete($photo->variants['medium']);

        $stats = $this->migrate();

        $this->assertSame(1, $stats['variants_copied']);
        Storage::disk('new_public')->assertExists($photo->variants['medium']);
    }

    public function test_a_truncated_file_on_the_new_disk_is_copied_again(): void
    {
        $photo = $this->upload(Place::factory()->create());
        $this->migrate();

        Storage::disk('new_public')->put($photo->variants['thumb'], 'cut');

        $stats = $this->migrate();

        $this->assertSame(1, $stats['variants_copied']);
        $this->assertSame(
            Storage::disk('old_public')->size($photo->variants['thumb']),
            Storage::disk('new_public')->size($photo->variants['thumb']),
        );
    }

    public function test_dry_run_counts_without_writing_or_deleting(): void
    {
        $photo = $this->upload(Place::factory()->create());

        $stats = $this->migrate(['dry_run' => true, 'delete_source' => true]);

        $this->assertSame(1, $stats['originals_copied']);
        $this->assertSame(3, $stats['variants_copied']);
        $this->assertSame(0, $stats['deleted_files']);

        Storage::disk('new_original')->assertMissing($photo->path);
        Storage::disk('new_public')->assertMissing($photo->variants['thumb']);
        Storage::disk('old_public')->assertExists($photo->variants['thumb']);
    }

    public function test_missing_variants_are_regenerated_from_the_original(): void
    {
        $photo = $this->upload(Place::factory()->create());
        Storage::disk('old_public')->delete($photo->variants['large']);
        Storage::disk('old_public')->delete($photo->variants['medium']);

        $stats = $this->migrate();

        $this->assertSame(1, $stats['reprocessed']);
        $this->assertSame([], $stats['failed']);

        $photo->refresh();
        $this->assertSame(PlacePhoto::STATUS_READY, $photo->status);
        foreach (['thumb', 'medium', 'large'] as $size) {
            Storage::disk('new_public')->assertExists($photo->variants[$size]);
        }
        [$width] = getimagesizefromstring(Storage::disk('new_public')->get($photo->variants['large']));
        $this->assertSame(1600, $width);
    }

    public function test_a_failed_photo_with_only_its_original_is_processed_on_the_new_disk(): void
    {
        $photo = $this->upload(Place::factory()->create());
        foreach ($photo->variants as $path) {
            Storage::disk('old_public')->delete($path);
        }
        $photo->forceFill(['variants' => null, 'status' => PlacePhoto::STATUS_FAILED])->save();

        $stats = $this->migrate();

        $this->assertSame(1, $stats['reprocessed']);
        $photo->refresh();
        $this->assertSame(PlacePhoto::STATUS_READY, $photo->status);
        Storage::disk('new_public')->assertExists($photo->variants['thumb']);
    }

    public function test_reprocess_regenerates_even_when_the_variants_exist(): void
    {
        $photo = $this->upload(Place::factory()->create());
        $this->migrate();

        Storage::disk('new_public')->put($photo->variants['thumb'], 'old bytes');

        $stats = $this->migrate(['reprocess' => true]);

        $this->assertSame(1, $stats['reprocessed']);
        $this->assertNotSame('old bytes', Storage::disk('new_public')->get($photo->variants['thumb']));
    }

    public function test_a_photo_with_no_file_anywhere_fails_without_stopping_the_others(): void
    {
        $place = Place::factory()->create();
        $lost = $this->upload($place);
        $fine = $this->upload($place);

        Storage::disk('old_original')->delete($lost->path);
        Storage::disk('old_public')->delete($lost->variants['thumb']);

        $stats = $this->migrate();

        $this->assertSame(2, $stats['photos']);
        $this->assertSame([$lost->id], array_keys($stats['failed']));
        Storage::disk('new_public')->assertExists($fine->variants['thumb']);
    }

    public function test_delete_source_removes_the_old_files_after_a_complete_copy(): void
    {
        $photo = $this->upload(Place::factory()->create());

        $stats = $this->migrate(['delete_source' => true]);

        $this->assertSame(4, $stats['deleted_files']);
        Storage::disk('old_original')->assertMissing($photo->path);
        foreach ($photo->variants as $path) {
            Storage::disk('old_public')->assertMissing($path);
            Storage::disk('new_public')->assertExists($path);
        }
        Storage::disk('new_original')->assertExists($photo->path);
    }

    public function test_delete_source_never_deletes_when_the_old_disk_is_the_new_one(): void
    {
        $photo = $this->upload(Place::factory()->create());

        $this->useDisks('old_public', 'old_original');
        $stats = app(PlacePhotoMigrator::class)->run('old_public', 'old_original', deleteSource: true);

        $this->assertSame(0, $stats['deleted_files']);
        $this->assertSame(1, $stats['already']);
        Storage::disk('old_public')->assertExists($photo->variants['thumb']);
        Storage::disk('old_original')->assertExists($photo->path);
    }

    public function test_processing_photos_and_demo_urls_are_skipped(): void
    {
        $processing = $this->upload(Place::factory()->create());
        $processing->forceFill(['status' => PlacePhoto::STATUS_PROCESSING])->save();

        // الـ factory بتعمل غلاف بروابط مطلقة (بيانات تجريبية)
        Place::factory()->published()->create();

        $stats = $this->migrate();

        $this->assertSame(1, $stats['processing']);
        $this->assertSame(1, $stats['external']);
        $this->assertSame(0, $stats['originals_copied'] + $stats['variants_copied']);
    }

    public function test_place_option_limits_the_run_to_one_place(): void
    {
        $one = $this->upload(Place::factory()->create());
        $other = $this->upload(Place::factory()->create());

        $stats = $this->migrate(['place' => $one->place_id]);

        $this->assertSame(1, $stats['photos']);
        Storage::disk('new_public')->assertExists($one->variants['thumb']);
        Storage::disk('new_public')->assertMissing($other->variants['thumb']);
    }

    public function test_the_command_prints_a_summary_and_fails_when_a_photo_cannot_be_moved(): void
    {
        $photo = $this->upload(Place::factory()->create());
        $this->useDisks('new_public', 'new_original');

        $this->artisan('photos:migrate', ['--from-disk' => 'old_public', '--from-original-disk' => 'old_original'])
            ->expectsOutputToContain('old_public ← new_public')
            ->assertSuccessful();

        Storage::disk('new_public')->assertExists($photo->variants['thumb']);

        Storage::disk('old_original')->delete($photo->path);
        Storage::disk('new_original')->delete($photo->path);
        Storage::disk('old_public')->delete($photo->variants['thumb']);
        Storage::disk('new_public')->delete($photo->variants['thumb']);

        $this->artisan('photos:migrate', ['--from-disk' => 'old_public', '--from-original-disk' => 'old_original'])
            ->assertFailed();
    }

    public function test_urls_follow_the_configured_disk(): void
    {
        $photo = $this->upload(Place::factory()->create());

        // الرابط بيتحسب وقت الطلب من الـ disk الحالي (R2 بدومينه)، من غير ما الصف يتغيّر
        Storage::fake('new_public', ['url' => 'https://photos.example.test']);
        $this->useDisks('new_public', 'new_original');

        $this->assertSame(
            'https://photos.example.test/'.$photo->variants['thumb'],
            $photo->fresh()->variantUrl('thumb'),
        );
    }
}
