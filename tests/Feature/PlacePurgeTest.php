<?php

namespace Tests\Feature;

use App\Models\Place;
use App\Models\PlacePhoto;
use App\Support\PlacePurge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * job المسح النهائي (03 «جدول الانتقالات»): المحذوف من أكتر من 30 يوم بيتمسح هو وصوره، والباقي ما بيتلمسش.
 */
class PlacePurgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('wain.photos.original_disk'));
        Storage::fake(config('wain.photos.disk'));
    }

    private function deletedDaysAgo(int $days, array $attributes = []): Place
    {
        $place = Place::factory()->published()->create($attributes);
        $place->delete();

        DB::table('places')->where('id', $place->id)->update(['deleted_at' => now()->subDays($days)]);

        return $place->refresh();
    }

    private function putFiles(Place $place): void
    {
        Storage::disk(config('wain.photos.original_disk'))->put("places/{$place->id}/original/a.jpg", 'original');
        Storage::disk(config('wain.photos.disk'))->put("places/{$place->id}/1-thumb.webp", 'thumb');
        Storage::disk(config('wain.photos.disk'))->put("places/{$place->id}/1-large.webp", 'large');
    }

    public function test_purges_a_place_deleted_more_than_30_days_ago_with_everything_under_it(): void
    {
        $place = $this->deletedDaysAgo(31);
        $this->putFiles($place);

        $this->assertGreaterThan(0, PlacePhoto::query()->where('place_id', $place->id)->count());
        $this->assertGreaterThan(0, DB::table('place_hours')->where('place_id', $place->id)->count());
        $this->assertGreaterThan(0, DB::table('place_category')->where('place_id', $place->id)->count());
        $this->assertGreaterThan(0, DB::table('attribute_place')->where('place_id', $place->id)->count());

        $this->assertSame(1, (new PlacePurge)->run());

        $this->assertNull(Place::withTrashed()->find($place->id));
        $this->assertSame(0, PlacePhoto::query()->where('place_id', $place->id)->count());
        $this->assertSame(0, DB::table('place_hours')->where('place_id', $place->id)->count());
        $this->assertSame(0, DB::table('place_category')->where('place_id', $place->id)->count());
        $this->assertSame(0, DB::table('attribute_place')->where('place_id', $place->id)->count());

        Storage::disk(config('wain.photos.original_disk'))->assertMissing("places/{$place->id}/original/a.jpg");
        Storage::disk(config('wain.photos.disk'))->assertMissing("places/{$place->id}/1-thumb.webp");
        Storage::disk(config('wain.photos.disk'))->assertMissing("places/{$place->id}/1-large.webp");
    }

    public function test_the_change_log_survives_the_purge(): void
    {
        $place = $this->deletedDaysAgo(40);
        $before = Activity::query()->where('subject_type', Place::class)->where('subject_id', $place->id)->count();
        $this->assertGreaterThan(0, $before);

        (new PlacePurge)->run();

        $this->assertGreaterThanOrEqual(
            $before,
            Activity::query()->where('subject_type', Place::class)->where('subject_id', $place->id)->count(),
        );
    }

    public function test_keeps_recently_deleted_and_live_places_with_their_files(): void
    {
        $recent = $this->deletedDaysAgo(29);
        $live = Place::factory()->published()->create();
        $hidden = Place::factory()->status(Place::STATUS_HIDDEN)->create();
        $this->putFiles($recent);
        $this->putFiles($live);

        $this->assertSame(0, (new PlacePurge)->run());

        $this->assertNotNull(Place::withTrashed()->find($recent->id));
        $this->assertNotNull(Place::find($live->id));
        $this->assertNotNull(Place::find($hidden->id));
        Storage::disk(config('wain.photos.disk'))->assertExists("places/{$recent->id}/1-thumb.webp");
        Storage::disk(config('wain.photos.disk'))->assertExists("places/{$live->id}/1-thumb.webp");
    }

    public function test_a_restore_window_place_can_still_be_restored_and_an_expired_one_cannot_be_found(): void
    {
        // ساعة قبل ما الـ 30 يوم يخلصوا
        $inWindow = $this->deletedDaysAgo(30);
        DB::table('places')->where('id', $inWindow->id)->update(['deleted_at' => now()->subDays(30)->addHour()]);
        $expired = $this->deletedDaysAgo(31);

        (new PlacePurge)->run();

        // لسه ينفع يتسترجع (نفس حد PlaceTransitions::restore)
        $this->assertNotNull(Place::withTrashed()->find($inWindow->id));
        $this->assertNull(Place::withTrashed()->find($expired->id));
    }

    public function test_purge_refuses_a_place_that_is_not_deleted(): void
    {
        $place = Place::factory()->published()->create();

        $this->expectException(LogicException::class);

        try {
            (new PlacePurge)->purge($place);
        } finally {
            $this->assertNotNull(Place::find($place->id));
        }
    }

    public function test_other_places_files_and_photos_are_not_touched(): void
    {
        $gone = $this->deletedDaysAgo(45);
        $other = Place::factory()->published()->create();
        $this->putFiles($gone);
        $this->putFiles($other);
        $otherPhotos = PlacePhoto::query()->where('place_id', $other->id)->count();

        (new PlacePurge)->run();

        $this->assertSame($otherPhotos, PlacePhoto::query()->where('place_id', $other->id)->count());
        Storage::disk(config('wain.photos.original_disk'))->assertExists("places/{$other->id}/original/a.jpg");
    }

    public function test_the_command_reports_how_many_were_purged(): void
    {
        $this->deletedDaysAgo(35);
        $this->deletedDaysAgo(60);
        $this->deletedDaysAgo(5);

        $this->artisan('places:purge-deleted')
            ->expectsOutputToContain('Purged 2 place(s)')
            ->assertSuccessful();
    }
}
