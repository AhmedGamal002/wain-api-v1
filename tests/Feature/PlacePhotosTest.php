<?php

namespace Tests\Feature;

use App\Events\PlaceChanged;
use App\Jobs\ProcessPlacePhoto;
use App\Models\Place;
use App\Models\PlacePhoto;
use App\Models\User;
use App\Support\PlacePhotoException;
use App\Support\PlacePhotoProcessor;
use App\Support\PlacePhotos;
use Database\Seeders\AttributeSeeder;
use Database\Seeders\CategorySeeder;
use Database\Seeders\CityAreaSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * صور المكان (03 القسم 4.5): الرفع والمعالجة لنسخ WebP والغلاف والحذف والترتيب. بتشتغل على disks مزيّفة
 * (`local` للأصل و`public` للنسخ) وعلى queue `sync`، فالمعالجة بتخلص جوه الرفع.
 */
class PlacePhotosTest extends TestCase
{
    use RefreshDatabase;

    private PlacePhotos $photos;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleSeeder::class, SettingSeeder::class, CategorySeeder::class, CityAreaSeeder::class, AttributeSeeder::class]);
        Storage::fake('local');
        Storage::fake('public');

        $this->photos = new PlacePhotos;
        $this->admin = User::factory()->create()->assignRole('admin');
        $this->actingAs($this->admin);
    }

    private function image(string $name, int $width, int $height): UploadedFile
    {
        return UploadedFile::fake()->image($name, $width, $height);
    }

    /** @return array{0: int, 1: int} */
    private function variantSize(PlacePhoto $photo, string $size): array
    {
        $info = getimagesizefromstring(Storage::disk('public')->get($photo->variants[$size]));

        return [$info[0], $info[1]];
    }

    public function test_a_gallery_photo_is_processed_into_three_webp_variants(): void
    {
        $place = Place::factory()->create();

        $photo = $this->photos->upload($place, $this->image('a.jpg', 2000, 1500), PlacePhotos::KIND_GALLERY, $this->admin)->refresh();

        $this->assertSame(PlacePhoto::STATUS_READY, $photo->status);
        $this->assertSame(PlacePhoto::TYPE_GALLERY, $photo->type);
        $this->assertSame([240, 180], $this->variantSize($photo, 'thumb'));
        $this->assertSame([800, 600], $this->variantSize($photo, 'medium'));
        $this->assertSame([1600, 1200], $this->variantSize($photo, 'large'));
        $this->assertSame(2000, $photo->width);

        // الأصل خاص والنسخ على الـ disk العام
        Storage::disk('local')->assertExists($photo->path);
        foreach ($photo->variants as $path) {
            Storage::disk('public')->assertExists($path);
        }
    }

    public function test_a_small_image_is_never_upscaled(): void
    {
        $place = Place::factory()->create();

        $photo = $this->photos->upload($place, $this->image('a.png', 900, 600), PlacePhotos::KIND_GALLERY, $this->admin)->refresh();

        $this->assertSame([240, 160], $this->variantSize($photo, 'thumb'));
        $this->assertSame([800, 533], $this->variantSize($photo, 'medium'));
        $this->assertSame([900, 600], $this->variantSize($photo, 'large'));
    }

    public function test_the_first_cover_becomes_the_cover_and_is_cropped_to_3_2(): void
    {
        $place = Place::factory()->create();

        $photo = $this->photos->upload($place, $this->image('cover.jpg', 2400, 1200), PlacePhotos::KIND_COVER, $this->admin)->refresh();

        $this->assertSame($photo->id, $place->refresh()->cover_photo_id);
        $this->assertSame([240, 160], $this->variantSize($photo, 'thumb'));
        $this->assertSame([800, 533], $this->variantSize($photo, 'medium'));
        $this->assertTrue($place->coverPhoto->isReady());
    }

    public function test_replacing_a_ready_cover_switches_only_when_the_new_one_is_ready(): void
    {
        $place = Place::factory()->complete()->create();
        $oldCover = $place->cover_photo_id;

        Queue::fake();
        $new = $this->photos->upload($place, $this->image('new.jpg', 1800, 1200), PlacePhotos::KIND_COVER, $this->admin);

        Queue::assertPushed(ProcessPlacePhoto::class, fn (ProcessPlacePhoto $job) => $job->photoId === $new->id && $job->makeCover);
        $this->assertSame($oldCover, $place->refresh()->cover_photo_id, 'الغلاف القديم بيفضل لحد ما الجديد يخلص');

        (new ProcessPlacePhoto($new->id, true))->handle(app(PlacePhotoProcessor::class));

        $this->assertSame($new->id, $place->refresh()->cover_photo_id);
        $this->assertSame(PlacePhoto::STATUS_READY, $new->refresh()->status);
    }

    public function test_it_refuses_non_images_small_covers_and_oversize_files(): void
    {
        $place = Place::factory()->create();

        $cases = [
            'مش صورة' => [UploadedFile::fake()->create('notes.pdf', 100, 'application/pdf'), PlacePhotos::KIND_GALLERY],
            'صغيرة' => [$this->image('small.jpg', 1000, 600), PlacePhotos::KIND_COVER],
            'عرض 800' => [$this->image('narrow.jpg', 600, 900), PlacePhotos::KIND_GALLERY],
            'ميجا' => [$this->image('huge.jpg', 1000, 800)->size(6000), PlacePhotos::KIND_GALLERY],
        ];

        foreach ($cases as $expected => [$file, $kind]) {
            try {
                $this->photos->upload($place, $file, $kind, $this->admin);
                $this->fail("المفروض يترفض: {$expected}");
            } catch (PlacePhotoException $e) {
                $this->assertStringContainsString($expected, $e->getMessage());
            }
        }

        $this->assertSame(0, $place->photos()->count());
    }

    public function test_the_gallery_has_a_limit_of_20(): void
    {
        $place = Place::factory()->create();

        for ($i = 0; $i < 20; $i++) {
            $place->photos()->create(['type' => 'gallery', 'path' => "p/{$i}.jpg", 'status' => 'ready', 'uploaded_by' => $this->admin->id, 'sort_order' => $i]);
        }

        $this->expectException(PlacePhotoException::class);
        $this->expectExceptionMessage('وصلت الحد');

        $this->photos->upload($place, $this->image('21.jpg', 1000, 700), PlacePhotos::KIND_GALLERY, $this->admin);
    }

    public function test_the_menu_is_stored_as_menu_type_and_allows_small_images(): void
    {
        $place = Place::factory()->create();

        $photo = $this->photos->upload($place, $this->image('menu.jpg', 500, 700), PlacePhotos::KIND_MENU, $this->admin)->refresh();

        $this->assertSame(PlacePhoto::TYPE_MENU, $photo->type);
        $this->assertNull($place->refresh()->cover_photo_id);
    }

    public function test_a_user_without_the_team_role_can_not_upload(): void
    {
        $place = Place::factory()->create();
        $owner = User::factory()->create()->assignRole('owner');

        $this->expectException(AuthorizationException::class);

        $this->photos->upload($place, $this->image('a.jpg', 1000, 700), PlacePhotos::KIND_GALLERY, $owner);
    }

    public function test_an_unsaved_place_can_not_receive_photos(): void
    {
        $this->expectException(PlacePhotoException::class);

        $this->photos->upload(new Place, $this->image('a.jpg', 1000, 700), PlacePhotos::KIND_GALLERY, $this->admin);
    }

    public function test_deleting_the_cover_of_a_published_place_is_refused_until_another_cover_is_set(): void
    {
        $place = Place::factory()->published()->create();
        $cover = $place->coverPhoto;
        $other = $this->photos->upload($place, $this->image('b.jpg', 1400, 900), PlacePhotos::KIND_GALLERY, $this->admin)->refresh();

        try {
            $this->photos->delete($place->refresh(), $cover, $this->admin);
            $this->fail('الغلاف على مكان منشور ما ينفعش يتمسح');
        } catch (PlacePhotoException $e) {
            $this->assertStringContainsString('غلاف', $e->getMessage());
        }

        $this->photos->makeCover($place->refresh(), $other, $this->admin);
        $this->photos->delete($place->refresh(), $cover, $this->admin);

        $this->assertSame($other->id, $place->refresh()->cover_photo_id);
        $this->assertNull(PlacePhoto::find($cover->id));
    }

    public function test_deleting_the_cover_of_a_draft_clears_it_and_removes_the_files(): void
    {
        $place = Place::factory()->create();
        $photo = $this->photos->upload($place, $this->image('cover.jpg', 1800, 1200), PlacePhotos::KIND_COVER, $this->admin)->refresh();
        $variants = $photo->variants;
        $original = $photo->path;

        $this->photos->delete($place->refresh(), $photo, $this->admin);

        $this->assertNull($place->refresh()->cover_photo_id);
        Storage::disk('local')->assertMissing($original);
        foreach ($variants as $path) {
            Storage::disk('public')->assertMissing($path);
        }
    }

    public function test_reorder_and_move_change_the_gallery_order(): void
    {
        $place = Place::factory()->create();
        [$a, $b, $c] = collect(['a', 'b', 'c'])
            ->map(fn ($n) => $this->photos->upload($place, $this->image("{$n}.jpg", 1000, 700), PlacePhotos::KIND_GALLERY, $this->admin)->refresh())
            ->all();

        $order = fn () => $place->photos()->where('type', 'gallery')->pluck('id')->all();
        $this->assertSame([$a->id, $b->id, $c->id], $order());

        $this->photos->reorder($place, 'gallery', [$c->id, $a->id, $b->id], $this->admin);
        $this->assertSame([$c->id, $a->id, $b->id], $order());

        $this->photos->move($place, $b->refresh(), -1, $this->admin);
        $this->assertSame([$c->id, $b->id, $a->id], $order());

        // الأول مفيش قبله: ما بيتحرّكش
        $this->photos->move($place, $c->refresh(), -1, $this->admin);
        $this->assertSame([$c->id, $b->id, $a->id], $order());
    }

    public function test_make_cover_needs_a_ready_gallery_photo(): void
    {
        $place = Place::factory()->create();
        $menu = $this->photos->upload($place, $this->image('m.jpg', 700, 900), PlacePhotos::KIND_MENU, $this->admin)->refresh();
        $processing = $place->photos()->create(['type' => 'gallery', 'path' => 'x.jpg', 'status' => 'processing', 'uploaded_by' => $this->admin->id]);

        foreach ([$menu, $processing] as $photo) {
            try {
                $this->photos->makeCover($place, $photo, $this->admin);
                $this->fail('المفروض يترفض');
            } catch (PlacePhotoException) {
            }
        }

        $this->assertNull($place->refresh()->cover_photo_id);
    }

    public function test_a_failed_job_marks_the_photo_failed_notifies_the_uploader_and_can_be_retried(): void
    {
        $place = Place::factory()->create();
        $photo = $place->photos()->create([
            'type' => 'gallery', 'path' => "places/{$place->id}/original/broken.jpg", 'status' => 'processing', 'uploaded_by' => $this->admin->id,
        ]);
        Storage::disk('local')->put($photo->path, 'ده مش صورة');

        $job = new ProcessPlacePhoto($photo->id);
        try {
            $job->handle(app(PlacePhotoProcessor::class));
            $this->fail('المعالجة المفروض تفشل');
        } catch (\Throwable $e) {
            $job->failed($e);
        }

        $this->assertSame(PlacePhoto::STATUS_FAILED, $photo->refresh()->status);
        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $this->admin->id)->count());

        // الأصل اتصلّح، و«حاول تاني» بيرجّعها ready
        Storage::disk('local')->put($photo->path, $this->image('ok.jpg', 1000, 700)->get());
        $this->photos->retry($place, $photo, $this->admin);

        $this->assertSame(PlacePhoto::STATUS_READY, $photo->refresh()->status);
    }

    public function test_photo_changes_are_in_the_activity_log_and_fire_place_changed(): void
    {
        $place = Place::factory()->create();
        Event::fake([PlaceChanged::class]);

        $this->photos->upload($place, $this->image('a.jpg', 1000, 700), PlacePhotos::KIND_GALLERY, $this->admin);

        Event::assertDispatched(PlaceChanged::class);
        $this->assertTrue(Activity::query()->where('subject_id', $place->id)->where('event', 'photos')->exists());
    }

    public function test_the_api_serves_the_variant_urls_of_the_configured_disk(): void
    {
        $place = Place::factory()->published()->create();
        $photo = $this->photos->upload($place, $this->image('a.jpg', 1400, 900), PlacePhotos::KIND_GALLERY, $this->admin)->refresh();
        $this->photos->makeCover($place->refresh(), $photo, $this->admin);

        $this->assertStringContainsString('/storage/places/', (string) $photo->variantUrl('medium'));

        $json = $this->getJson("/api/v1/places/{$place->refresh()->slug}", ['Accept-Language' => 'ar'])->assertOk()->json('data');

        // الغلاف الأول في `photos`، وروابطه من الـ disk العام (مش الافتراضي)
        $this->assertSame($photo->variantUrl('large'), $json['photos'][0]['large']);
        $this->assertSame($photo->variantUrl('thumb'), $json['cover']['thumb']);
    }
}
