<?php

namespace Tests\Feature;

use App\Events\ReviewPublished;
use App\Events\ReviewRejected;
use App\Models\Attribute;
use App\Models\Place;
use App\Models\Review;
use App\Models\ReviewPhoto;
use App\Models\User;
use App\Support\ReviewModeration;
use App\Support\ReviewModerationException;
use Database\Seeders\AttributeSeeder;
use Database\Seeders\CategorySeeder;
use Database\Seeders\CityAreaSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * قرار الفريق على التقييمات (A6/F8، 02): موافقة ورفض لتقييم جديد ولتعديل منشور، وحساب المتوسط من المنشور بس،
 * والأحداث اللي F11 بيشترك فيها.
 */
class ReviewModerationTest extends TestCase
{
    use RefreshDatabase;

    private Place $place;

    private User $moderator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleSeeder::class, CategorySeeder::class, CityAreaSeeder::class, AttributeSeeder::class]);

        $this->place = Place::factory()->published()->create();
        $this->moderator = User::factory()->create()->assignRole('moderator');
    }

    private function moderation(): ReviewModeration
    {
        return app(ReviewModeration::class);
    }

    private function photo(?Review $review, User $owner, string $name = 'a'): ReviewPhoto
    {
        Storage::disk(config('wain.photos.original_disk'))->put("review-originals/{$owner->id}/{$name}.jpg", 'x');

        return ReviewPhoto::query()->create([
            'review_id' => $review?->id,
            'user_id' => $owner->id,
            'path' => "review-originals/{$owner->id}/{$name}.jpg",
            'variants' => ['thumb' => "reviews/{$owner->id}/{$name}-thumb.webp"],
            'status' => 'ready',
            'created_at' => now(),
        ]);
    }

    // ───────────────────────── تقييم جديد ─────────────────────────

    public function test_approving_publishes_the_review_and_counts_it_in_the_average(): void
    {
        Event::fake([ReviewPublished::class, ReviewRejected::class]);

        $review = Review::factory()->rating(4)->create(['place_id' => $this->place->id]);

        $this->moderation()->approve($review, $this->moderator);

        $review->refresh();
        $this->assertSame('published', $review->status);
        $this->assertNotNull($review->published_at);
        $this->assertSame($this->moderator->id, $review->moderated_by);

        $this->place->refresh();
        $this->assertSame(4.0, (float) $this->place->rating_avg);
        $this->assertSame(1, (int) $this->place->rating_count);

        Event::assertDispatched(ReviewPublished::class, fn (ReviewPublished $event) => $event->review->is($review) && $event->isEdit === false);
        Event::assertNotDispatched(ReviewRejected::class);

        $log = Activity::query()->where('log_name', 'reviews')->where('event', 'approved')->firstOrFail();
        $this->assertSame($this->moderator->id, $log->causer_id);
    }

    public function test_the_average_counts_published_reviews_only(): void
    {
        Review::factory()->published()->rating(5)->create(['place_id' => $this->place->id]);
        Review::factory()->rating(1)->create(['place_id' => $this->place->id]);
        Review::factory()->rejected()->rating(1)->create(['place_id' => $this->place->id]);
        Review::factory()->hidden()->rating(1)->create(['place_id' => $this->place->id]);

        $pending = Review::factory()->rating(2)->create(['place_id' => $this->place->id]);
        $this->moderation()->approve($pending, $this->moderator);

        $this->place->refresh();
        $this->assertSame(3.5, (float) $this->place->rating_avg);
        $this->assertSame(2, (int) $this->place->rating_count);
    }

    public function test_a_rejection_keeps_the_average_and_tells_the_owner_why(): void
    {
        Event::fake([ReviewPublished::class, ReviewRejected::class]);

        Review::factory()->published()->rating(5)->create(['place_id' => $this->place->id]);
        $this->place->forceFill(['rating_avg' => 5, 'rating_count' => 1])->save();

        $review = Review::factory()->rating(1)->create(['place_id' => $this->place->id]);

        $this->moderation()->reject($review, $this->moderator, 'ad');

        $review->refresh();
        $this->assertSame('rejected', $review->status);
        $this->assertSame('إعلان', $review->status_reason);
        $this->assertNull($review->published_at);

        $this->place->refresh();
        $this->assertSame(5.0, (float) $this->place->rating_avg);
        $this->assertSame(1, (int) $this->place->rating_count);

        Event::assertDispatched(ReviewRejected::class, fn (ReviewRejected $event) => $event->reason === 'إعلان' && $event->isEdit === false);
        Event::assertNotDispatched(ReviewPublished::class);
    }

    public function test_the_other_reason_needs_notes_and_an_unknown_reason_is_refused(): void
    {
        $review = Review::factory()->create(['place_id' => $this->place->id]);

        try {
            $this->moderation()->reject($review, $this->moderator, 'other', '  ');
            $this->fail('other without notes should be refused');
        } catch (ReviewModerationException $e) {
            $this->assertSame('اكتب تفاصيل السبب', $e->getMessage());
        }

        $this->expectException(ReviewModerationException::class);
        $this->moderation()->reject($review, $this->moderator, 'made-up');
    }

    public function test_a_reason_with_notes_keeps_both(): void
    {
        $review = Review::factory()->create(['place_id' => $this->place->id]);

        $this->moderation()->reject($review, $this->moderator, 'photos', 'الصورة التالتة فيها وشوش');

        $this->assertSame('صور غير مناسبة: الصورة التالتة فيها وشوش', $review->refresh()->status_reason);
    }

    public function test_a_decision_is_taken_once(): void
    {
        $review = Review::factory()->create(['place_id' => $this->place->id]);
        $this->moderation()->approve($review, $this->moderator);

        $this->expectException(ReviewModerationException::class);
        $this->moderation()->reject($review->refresh(), $this->moderator, 'ad');
    }

    public function test_a_regular_user_cannot_decide(): void
    {
        $review = Review::factory()->create(['place_id' => $this->place->id]);

        $this->expectException(AuthorizationException::class);
        $this->moderation()->approve($review, User::factory()->create());
    }

    public function test_approving_with_removed_photos_deletes_them_and_their_files(): void
    {
        Storage::fake('local');
        config(['wain.photos.original_disk' => 'local']);

        $author = User::factory()->create();
        $review = Review::factory()->create(['place_id' => $this->place->id, 'user_id' => $author->id]);
        $keep = $this->photo($review, $author, 'keep');
        $drop = $this->photo($review, $author, 'drop');

        $this->moderation()->approve($review, $this->moderator, [$drop->id]);

        $this->assertTrue(ReviewPhoto::query()->whereKey($keep->id)->exists());
        $this->assertFalse(ReviewPhoto::query()->whereKey($drop->id)->exists());
        Storage::disk('local')->assertMissing($drop->path);
        Storage::disk('local')->assertExists($keep->path);
        $this->assertSame('published', $review->refresh()->status);
    }

    // ───────────────────────── تعديل منشور ─────────────────────────

    public function test_approving_an_edit_applies_the_new_values_and_recomputes_the_average(): void
    {
        Storage::fake('local');
        config(['wain.photos.original_disk' => 'local']);
        Event::fake([ReviewPublished::class]);

        $author = User::factory()->create();
        $oldPhoto = $this->photo(null, $author, 'old');
        $newPhoto = $this->photo(null, $author, 'new');
        $attribute = Attribute::query()->where('is_active', true)->firstOrFail();

        $review = Review::factory()->withPendingEdit([
            'rating' => 2,
            'body' => 'الخدمة اتغيّرت للأسوأ بعد التجديدات',
            'visited_at' => '2026-10-01',
            'attributes' => [$attribute->slug],
            'photo_ids' => [$newPhoto->id],
        ])->rating(5)->create(['place_id' => $this->place->id, 'user_id' => $author->id, 'body' => 'تجربة ممتازة جداً من الأول']);
        $oldPhoto->forceFill(['review_id' => $review->id])->save();

        $this->place->forceFill(['rating_avg' => 5, 'rating_count' => 1])->save();

        $this->moderation()->approve($review, $this->moderator);

        $review->refresh();
        $this->assertSame(2, $review->rating);
        $this->assertSame('الخدمة اتغيّرت للأسوأ بعد التجديدات', $review->body);
        $this->assertSame('2026-10-01', $review->visited_at->toDateString());
        $this->assertSame('none', $review->edit_status);
        $this->assertNull($review->pending_payload);
        $this->assertSame('published', $review->status);
        $this->assertSame([$attribute->id], $review->reviewAttributes()->pluck('attributes.id')->all());
        $this->assertSame([$newPhoto->id], $review->photos()->pluck('id')->all());
        $this->assertFalse(ReviewPhoto::query()->whereKey($oldPhoto->id)->exists());

        $this->assertSame(2.0, (float) $this->place->refresh()->rating_avg);

        Event::assertDispatched(ReviewPublished::class, fn (ReviewPublished $event) => $event->isEdit === true);
    }

    public function test_rejecting_an_edit_keeps_the_published_version(): void
    {
        Event::fake([ReviewRejected::class]);

        $review = Review::factory()->withPendingEdit([
            'rating' => 1, 'body' => 'لا', 'visited_at' => null, 'attributes' => [], 'photo_ids' => [],
        ])->rating(5)->create(['place_id' => $this->place->id, 'body' => 'تجربة ممتازة جداً من الأول']);
        $this->place->forceFill(['rating_avg' => 5, 'rating_count' => 1])->save();

        $this->moderation()->reject($review, $this->moderator, 'off_topic');

        $review->refresh();
        $this->assertSame('published', $review->status);
        $this->assertSame(5, $review->rating);
        $this->assertSame('تجربة ممتازة جداً من الأول', $review->body);
        $this->assertSame('rejected', $review->edit_status);
        $this->assertSame('مش عن المكان', $review->edit_reason);
        $this->assertNull($review->pending_payload);
        $this->assertSame(5.0, (float) $this->place->refresh()->rating_avg);

        Event::assertDispatched(ReviewRejected::class, fn (ReviewRejected $event) => $event->isEdit === true);
    }

    public function test_the_photos_a_moderator_can_remove_cover_the_edit_too(): void
    {
        $author = User::factory()->create();
        $current = $this->photo(null, $author, 'current');
        $incoming = $this->photo(null, $author, 'incoming');

        $review = Review::factory()->withPendingEdit([
            'rating' => 4, 'body' => null, 'visited_at' => null, 'attributes' => [], 'photo_ids' => [$incoming->id],
        ])->create(['place_id' => $this->place->id, 'user_id' => $author->id]);
        $current->forceFill(['review_id' => $review->id])->save();

        $ids = collect(ReviewModeration::photosUnderReview($review->refresh()->load('photos')))->pluck('id')->sort()->values()->all();

        $this->assertSame([$current->id, $incoming->id], $ids);
    }

    // ───────────────────────── الدور ─────────────────────────

    public function test_a_moderator_can_decide_unlike_publishing_a_place(): void
    {
        $review = Review::factory()->create(['place_id' => $this->place->id]);

        $this->moderation()->approve($review, $this->moderator);

        $this->assertSame('published', $review->refresh()->status);
    }
}
