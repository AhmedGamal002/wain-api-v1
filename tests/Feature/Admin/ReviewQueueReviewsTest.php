<?php

namespace Tests\Feature\Admin;

use App\Filament\Pages\ReviewQueue\PendingReviews;
use App\Models\Place;
use App\Models\Review;
use App\Models\ReviewPhoto;
use App\Models\User;
use Database\Seeders\AttributeSeeder;
use Database\Seeders\CategorySeeder;
use Database\Seeders\CityAreaSeeder;
use Database\Seeders\RoleSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * تاب «تقييمات» في طابور المراجعة (A6، أسبوع 11): اللي فيه، والقرار من جواه. قواعد القرار نفسها في
 * `ReviewModerationTest`، والهيكل (التابز والعمر) في `ReviewQueueTest`.
 */
class ReviewQueueReviewsTest extends TestCase
{
    use RefreshDatabase;

    private Place $place;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleSeeder::class, CategorySeeder::class, CityAreaSeeder::class, AttributeSeeder::class]);
        Filament::setCurrentPanel('admin');

        $this->place = Place::factory()->published()->create(['name_ar' => 'مطعم الفيروز']);
    }

    private function asModerator(): User
    {
        $user = User::factory()->create()->assignRole('moderator');
        $this->actingAs($user);

        return $user;
    }

    private function queue()
    {
        return Livewire::test(PendingReviews::class);
    }

    private function pending(int $hoursAgo = 1, array $attributes = []): Review
    {
        $review = Review::factory()->create(['place_id' => $this->place->id] + $attributes);

        DB::table('reviews')->where('id', $review->id)->update(['submitted_at' => now()->subHours($hoursAgo)]);

        return $review->refresh();
    }

    public function test_a_regular_user_gets_403_and_the_team_gets_in(): void
    {
        $this->actingAs(User::factory()->create());
        $this->get(PendingReviews::getUrl())->assertForbidden();

        $this->asModerator();
        $this->get('/admin/review-queue/reviews')->assertOk()->assertSee('تقييمات');
    }

    public function test_new_reviews_and_pending_edits_show_oldest_first_and_nothing_else(): void
    {
        $this->asModerator();

        $newer = $this->pending(2);
        $oldest = $this->pending(70);
        $edit = Review::factory()->withPendingEdit(['rating' => 2, 'body' => null, 'visited_at' => null, 'attributes' => [], 'photo_ids' => []])
            ->create(['place_id' => Place::factory()->published()->create()->id]);
        DB::table('reviews')->where('id', $edit->id)->update(['submitted_at' => now()->subHours(30)]);

        $published = Review::factory()->published()->create(['place_id' => $this->place->id]);
        $rejected = Review::factory()->rejected()->create(['place_id' => $this->place->id]);

        $this->queue()
            ->assertCanSeeTableRecords([$oldest, $edit->refresh(), $newer], inOrder: true)
            ->assertCanNotSeeTableRecords([$published, $rejected]);
    }

    public function test_the_count_and_the_overdue_follow_the_forty_eight_hours(): void
    {
        $this->pending(1);
        $this->pending(49);
        Review::factory()->published()->create(['place_id' => $this->place->id]);

        $this->assertSame(2, PendingReviews::queueCount());
        $this->assertSame(1, PendingReviews::overdueCount());
    }

    public function test_the_rejected_count_of_the_author_is_on_the_row(): void
    {
        $this->asModerator();

        $author = User::factory()->create(['name' => 'منى']);
        Review::factory()->rejected()->count(2)->create(['user_id' => $author->id]);
        $this->pending(1, ['user_id' => $author->id]);

        $this->queue()->assertSee('اترفض له 2 تقييم');
    }

    public function test_approving_from_the_queue_publishes_and_updates_the_place_rating(): void
    {
        $this->asModerator();
        $review = $this->pending(1, ['rating' => 4]);

        $this->queue()
            ->callAction(TestAction::make('publish')->table($review))
            ->assertNotified();

        $this->assertSame('published', $review->refresh()->status);
        $this->assertSame(4.0, (float) $this->place->refresh()->rating_avg);

        $this->queue()->assertCanNotSeeTableRecords([$review]);
    }

    public function test_rejecting_needs_a_reason(): void
    {
        $this->asModerator();
        $review = $this->pending();

        $this->queue()
            ->callAction(TestAction::make('reject')->table($review), ['reason' => null])
            ->assertHasFormErrors(['reason' => 'required']);

        $this->queue()
            ->callAction(TestAction::make('reject')->table($review), ['reason' => 'other', 'notes' => ''])
            ->assertHasFormErrors(['notes' => 'required']);

        $this->assertSame('pending', $review->refresh()->status);

        $this->queue()
            ->callAction(TestAction::make('reject')->table($review), ['reason' => 'abusive', 'notes' => null])
            ->assertHasNoFormErrors();

        $review->refresh();
        $this->assertSame('rejected', $review->status);
        $this->assertSame('ألفاظ غير لائقة', $review->status_reason);
    }

    public function test_approving_with_a_removed_photo(): void
    {
        Storage::fake('local');
        config(['wain.photos.original_disk' => 'local']);
        $this->asModerator();

        $author = User::factory()->create();
        $review = $this->pending(1, ['user_id' => $author->id]);
        $keep = ReviewPhoto::query()->create(['review_id' => $review->id, 'user_id' => $author->id, 'path' => 'a.jpg', 'variants' => ['thumb' => 'a.webp'], 'status' => 'ready', 'created_at' => now()]);
        $drop = ReviewPhoto::query()->create(['review_id' => $review->id, 'user_id' => $author->id, 'path' => 'b.jpg', 'variants' => ['thumb' => 'b.webp'], 'status' => 'ready', 'created_at' => now()]);

        $this->queue()
            ->assertActionVisible(TestAction::make('approveWithoutPhotos')->table($review))
            ->callAction(TestAction::make('approveWithoutPhotos')->table($review), ['photos' => [$drop->id]])
            ->assertNotified();

        $this->assertSame('published', $review->refresh()->status);
        $this->assertTrue(ReviewPhoto::query()->whereKey($keep->id)->exists());
        $this->assertFalse(ReviewPhoto::query()->whereKey($drop->id)->exists());
    }

    public function test_the_remove_photo_action_is_hidden_when_there_are_no_photos(): void
    {
        $this->asModerator();
        $review = $this->pending();

        $this->queue()->assertActionHidden(TestAction::make('approveWithoutPhotos')->table($review));
    }

    public function test_the_keyboard_helper_lists_the_rows_with_their_permissions(): void
    {
        $this->asModerator();
        $review = $this->pending();

        $items = $this->queue()->instance()->keyboardItems();

        $this->assertCount(1, $items);
        $this->assertSame($review->id, $items[0]['id']);
        $this->assertTrue($items[0]['publish']);
        $this->assertTrue($items[0]['reject']);
    }
}
