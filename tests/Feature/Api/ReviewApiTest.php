<?php

namespace Tests\Feature\Api;

use App\Jobs\ProcessReviewPhoto;
use App\Models\Attribute;
use App\Models\Place;
use App\Models\Review;
use App\Models\ReviewPhoto;
use App\Models\User;
use App\Support\PlaceRating;
use Database\Seeders\AttributeSeeder;
use Database\Seeders\CategorySeeder;
use Database\Seeders\CityAreaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * F8 (02، 06 القسم 3): كتابة التقييم وقراءته وتعديله وحذفه ورفع صوره. قرار الفريق وحساب المتوسط في
 * `ReviewModerationTest`، واللوحة في `Admin/ReviewQueueReviewsTest`.
 */
class ReviewApiTest extends TestCase
{
    use RefreshDatabase;

    private const AR = ['Accept-Language' => 'ar'];

    private Place $place;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([CategorySeeder::class, CityAreaSeeder::class, AttributeSeeder::class]);
        RateLimiter::clear('reviews');

        $this->place = Place::factory()->published()->create();
        $this->user = User::factory()->create();
    }

    private function reviewUrl(?Place $place = null): string
    {
        return '/api/v1/places/'.($place ?? $this->place)->slug.'/reviews';
    }

    private function payload(array $overrides = []): array
    {
        return array_merge(['rating' => 5, 'body' => 'مكان هادي والخدمة سريعة جداً'], $overrides);
    }

    // ───────────────────────── القراءة ─────────────────────────

    public function test_only_published_reviews_are_listed_newest_first_with_a_summary(): void
    {
        $old = Review::factory()->published()->rating(5)->create(['place_id' => $this->place->id, 'published_at' => now()->subDays(3)]);
        $new = Review::factory()->published()->rating(3)->create(['place_id' => $this->place->id, 'published_at' => now()->subDay()]);
        Review::factory()->rating(1)->create(['place_id' => $this->place->id]);
        Review::factory()->rejected()->create(['place_id' => $this->place->id]);
        Review::factory()->hidden()->create(['place_id' => $this->place->id]);
        Review::factory()->published()->create(['place_id' => Place::factory()->published()]);

        $this->place->forceFill(['rating_avg' => 4, 'rating_count' => 2])->save();

        $this->getJson($this->reviewUrl(), self::AR)
            ->assertOk()
            ->assertJsonPath('data.0.id', $new->id)
            ->assertJsonPath('data.1.id', $old->id)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('summary.count', 2)
            ->assertJsonPath('summary.avg', 4)
            ->assertJsonPath('summary.distribution.5', 1)
            ->assertJsonPath('summary.distribution.3', 1)
            ->assertJsonPath('summary.distribution.1', 0)
            ->assertJsonPath('mine', null);
    }

    public function test_a_review_never_shows_internal_fields_or_the_email(): void
    {
        Review::factory()->published()->create(['place_id' => $this->place->id]);

        $row = $this->getJson($this->reviewUrl(), self::AR)->assertOk()->json('data.0');

        foreach (['moderated_by', 'moderated_at', 'reports_count', 'pending_payload', 'status', 'status_reason', 'edit'] as $hidden) {
            $this->assertArrayNotHasKey($hidden, $row);
        }

        $this->assertArrayNotHasKey('email', $row['user']);
        $this->assertFalse($row['is_mine']);
    }

    public function test_the_owner_sees_their_own_pending_review_as_mine_and_nobody_else_does(): void
    {
        $mine = Review::factory()->create(['place_id' => $this->place->id, 'user_id' => $this->user->id]);

        $this->actingAs($this->user, 'sanctum')
            ->getJson($this->reviewUrl(), self::AR)
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('mine.id', $mine->id)
            ->assertJsonPath('mine.status', 'pending')
            ->assertJsonPath('mine.is_mine', true);

        // الـ guard بيفتكر اليوزر جوه نفس الـ process: زائر حقيقي = request من غير token
        app('auth')->forgetGuards();

        $this->getJson($this->reviewUrl(), self::AR)->assertOk()->assertJsonPath('mine', null);
    }

    public function test_a_rejected_review_shows_its_reason_to_the_owner_only(): void
    {
        $mine = Review::factory()->rejected('إعلان')->create(['place_id' => $this->place->id, 'user_id' => $this->user->id]);

        $this->actingAs($this->user, 'sanctum')
            ->getJson($this->reviewUrl(), self::AR)
            ->assertJsonPath('mine.id', $mine->id)
            ->assertJsonPath('mine.status', 'rejected')
            ->assertJsonPath('mine.status_reason', 'إعلان');
    }

    public function test_a_deleted_reviewer_shows_as_a_deleted_user_without_a_name(): void
    {
        $author = User::factory()->create(['name' => 'سارة']);
        Review::factory()->published()->create(['place_id' => $this->place->id, 'user_id' => $author->id]);

        $author->delete();

        $this->getJson($this->reviewUrl(), self::AR)
            ->assertOk()
            ->assertJsonPath('data.0.user.name', null)
            ->assertJsonPath('data.0.user.is_deleted', true);
    }

    public function test_the_list_is_paginated(): void
    {
        Review::factory()->published()->count(12)->create(['place_id' => $this->place->id]);

        $this->getJson($this->reviewUrl().'?per_page=5&page=3', self::AR)
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.last_page', 3)
            ->assertJsonPath('meta.per_page', 5)
            ->assertJsonPath('meta.total', 12);
    }

    public function test_a_hidden_place_answers_place_not_available(): void
    {
        $hidden = Place::factory()->complete()->status(Place::STATUS_HIDDEN)->create();

        $this->getJson($this->reviewUrl($hidden), self::AR)
            ->assertNotFound()
            ->assertJsonPath('code', 'PLACE_NOT_AVAILABLE');
    }

    public function test_the_place_detail_carries_the_latest_five_and_the_distribution(): void
    {
        Review::factory()->published()->count(7)->create(['place_id' => $this->place->id]);
        Review::factory()->create(['place_id' => $this->place->id]);

        $response = $this->getJson('/api/v1/places/'.$this->place->slug, self::AR)->assertOk();

        $this->assertCount(5, $response->json('data.latest_reviews'));
        $this->assertSame(7, array_sum($response->json('data.rating_distribution')));
    }

    // ───────────────────────── الكتابة ─────────────────────────

    public function test_a_verified_user_writes_a_review_and_it_waits_for_approval(): void
    {
        $attribute = Attribute::query()->where('is_active', true)->firstOrFail();

        $this->actingAs($this->user, 'sanctum')
            ->postJson($this->reviewUrl(), $this->payload([
                'attributes' => [$attribute->slug],
                'visited_at' => '2026-09',
            ]), self::AR)
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.is_mine', true)
            ->assertJsonPath('data.visited_at', '2026-09')
            ->assertJsonPath('data.attributes.0.slug', $attribute->slug);

        $review = Review::query()->firstOrFail();
        $this->assertSame($this->user->id, $review->user_id);
        $this->assertSame('pending', $review->status);
        $this->assertNull($review->published_at);
        $this->assertSame('2026-09-01', $review->visited_at->toDateString());

        // لسه مش في القايمة العامة ولا في المتوسط
        $this->getJson($this->reviewUrl(), self::AR)->assertJsonCount(0, 'data');
        $this->assertSame(0, (int) $this->place->refresh()->rating_count);
    }

    public function test_a_guest_cannot_write_a_review(): void
    {
        $this->postJson($this->reviewUrl(), $this->payload(), self::AR)
            ->assertUnauthorized()
            ->assertJsonPath('code', 'UNAUTHENTICATED');
    }

    public function test_an_unverified_email_gets_email_not_verified(): void
    {
        $unverified = User::factory()->unverified()->create();

        $this->actingAs($unverified, 'sanctum')
            ->postJson($this->reviewUrl(), $this->payload(), self::AR)
            ->assertForbidden()
            ->assertJsonPath('code', 'EMAIL_NOT_VERIFIED');

        $this->assertSame(0, Review::query()->count());
    }

    public function test_one_review_per_user_and_place(): void
    {
        $this->actingAs($this->user, 'sanctum')->postJson($this->reviewUrl(), $this->payload(), self::AR)->assertCreated();

        $this->actingAs($this->user, 'sanctum')
            ->postJson($this->reviewUrl(), $this->payload(['rating' => 2]), self::AR)
            ->assertStatus(409)
            ->assertJsonPath('code', 'ALREADY_REVIEWED');

        $this->assertSame(1, Review::query()->count());
    }

    public function test_the_rating_is_required_and_between_one_and_five(): void
    {
        $user = $this->actingAs($this->user, 'sanctum');

        $user->postJson($this->reviewUrl(), ['body' => 'مكان حلو جداً فعلاً'], self::AR)
            ->assertUnprocessable()->assertJsonValidationErrors('rating');
        $user->postJson($this->reviewUrl(), $this->payload(['rating' => 0]), self::AR)
            ->assertUnprocessable()->assertJsonValidationErrors('rating');
        $user->postJson($this->reviewUrl(), $this->payload(['rating' => 6]), self::AR)
            ->assertUnprocessable()->assertJsonValidationErrors('rating');
        $user->postJson($this->reviewUrl(), $this->payload(['rating' => 'خمسة']), self::AR)
            ->assertUnprocessable()->assertJsonValidationErrors('rating');
    }

    public function test_the_body_is_optional_but_between_ten_and_a_thousand_characters(): void
    {
        $user = $this->actingAs($this->user, 'sanctum');

        $user->postJson($this->reviewUrl(), $this->payload(['body' => 'حلو']), self::AR)
            ->assertUnprocessable()->assertJsonValidationErrors('body');
        $user->postJson($this->reviewUrl(), $this->payload(['body' => str_repeat('ب', 1001)]), self::AR)
            ->assertUnprocessable()->assertJsonValidationErrors('body');

        // من غير رأي أو برأي فاضي: مقبول
        $user->postJson($this->reviewUrl(), ['rating' => 4], self::AR)->assertCreated();
        $this->assertNull(Review::query()->firstOrFail()->body);
    }

    public function test_phone_numbers_and_links_are_refused_in_the_body(): void
    {
        $user = $this->actingAs($this->user, 'sanctum');

        foreach ([
            'كلمني على 01012345678 وأنا أوصفلك',
            'اتصل ٠١٠١٢٣٤٥٦٧٨ بسرعة والله',
            'رقمي 010 1234 5678 للتواصل',
            'زوروا www.example.com فيه عروض',
            'شوفوا الصفحة https://wa.me/20101234567',
            'تابعونا على @best_place_eg دايماً',
            'الموقع example.com ممتاز جداً',
        ] as $body) {
            $user->postJson($this->reviewUrl(), $this->payload(['body' => $body]), self::AR)
                ->assertUnprocessable()
                ->assertJsonValidationErrors('body');
        }

        $this->assertSame(0, Review::query()->count());
    }

    public function test_prices_and_dates_are_not_mistaken_for_phone_numbers(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->postJson($this->reviewUrl(), $this->payload(['body' => 'الحساب طلع 450 جنيه للفرد وزرته في 2026 مرتين']), self::AR)
            ->assertCreated();
    }

    public function test_attributes_are_limited_to_five_and_must_exist(): void
    {
        $slugs = Attribute::query()->where('is_active', true)->limit(6)->pluck('slug')->all();
        $user = $this->actingAs($this->user, 'sanctum');

        $user->postJson($this->reviewUrl(), $this->payload(['attributes' => $slugs]), self::AR)
            ->assertUnprocessable()->assertJsonValidationErrors('attributes');
        $user->postJson($this->reviewUrl(), $this->payload(['attributes' => ['no-such-attribute']]), self::AR)
            ->assertUnprocessable()->assertJsonValidationErrors('attributes.0');
    }

    public function test_the_visit_month_cannot_be_in_the_future(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->postJson($this->reviewUrl(), $this->payload(['visited_at' => now('Africa/Cairo')->addMonth()->format('Y-m')]), self::AR)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('visited_at');

        $this->actingAs($this->user, 'sanctum')
            ->postJson($this->reviewUrl(), $this->payload(['visited_at' => now('Africa/Cairo')->format('Y-m')]), self::AR)
            ->assertCreated();
    }

    public function test_a_hidden_or_closed_place_takes_no_new_review(): void
    {
        $closed = Place::factory()->complete()->status(Place::STATUS_CLOSED)->create(['closed_at' => now()->subWeek()]);

        $this->actingAs($this->user, 'sanctum')
            ->postJson($this->reviewUrl($closed), $this->payload(), self::AR)
            ->assertNotFound()
            ->assertJsonPath('code', 'PLACE_NOT_AVAILABLE');
    }

    public function test_photos_must_be_the_users_own_and_unattached(): void
    {
        $mine = ReviewPhoto::query()->create(['user_id' => $this->user->id, 'path' => 'x/a.jpg', 'status' => 'ready', 'created_at' => now()]);
        $other = ReviewPhoto::query()->create(['user_id' => User::factory()->create()->id, 'path' => 'x/b.jpg', 'status' => 'ready', 'created_at' => now()]);
        $taken = ReviewPhoto::query()->create([
            'user_id' => $this->user->id,
            'review_id' => Review::factory()->create(['place_id' => Place::factory()->published()])->id,
            'path' => 'x/c.jpg',
            'status' => 'ready',
            'created_at' => now(),
        ]);

        $user = $this->actingAs($this->user, 'sanctum');

        $user->postJson($this->reviewUrl(), $this->payload(['photo_ids' => [$other->id]]), self::AR)
            ->assertUnprocessable()->assertJsonValidationErrors('photo_ids.0');
        $user->postJson($this->reviewUrl(), $this->payload(['photo_ids' => [$taken->id]]), self::AR)
            ->assertUnprocessable()->assertJsonValidationErrors('photo_ids.0');

        $user->postJson($this->reviewUrl(), $this->payload(['photo_ids' => [$mine->id]]), self::AR)
            ->assertCreated()
            ->assertJsonPath('data.photos.0.id', $mine->id);

        $this->assertNotNull($mine->refresh()->review_id);
    }

    public function test_at_most_five_photos(): void
    {
        $ids = [];
        foreach (range(1, 6) as $i) {
            $ids[] = ReviewPhoto::query()->create(['user_id' => $this->user->id, 'path' => "x/{$i}.jpg", 'status' => 'ready', 'created_at' => now()])->id;
        }

        $this->actingAs($this->user, 'sanctum')
            ->postJson($this->reviewUrl(), $this->payload(['photo_ids' => $ids]), self::AR)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('photo_ids');
    }

    // ───────────────────────── التعديل ─────────────────────────

    public function test_editing_a_pending_review_changes_it_in_place(): void
    {
        $review = Review::factory()->create(['place_id' => $this->place->id, 'user_id' => $this->user->id, 'rating' => 2]);

        $this->actingAs($this->user, 'sanctum')
            ->putJson("/api/v1/reviews/{$review->id}", $this->payload(['rating' => 4]), self::AR)
            ->assertOk()
            ->assertJsonPath('data.rating', 4)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.edit', null);

        $this->assertSame(4, $review->refresh()->rating);
    }

    public function test_editing_a_rejected_review_sends_it_back_to_review_and_clears_the_reason(): void
    {
        $review = Review::factory()->rejected('إعلان')->create(['place_id' => $this->place->id, 'user_id' => $this->user->id]);

        $this->actingAs($this->user, 'sanctum')
            ->putJson("/api/v1/reviews/{$review->id}", $this->payload(), self::AR)
            ->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.status_reason', null);
    }

    public function test_editing_a_published_review_keeps_the_published_values_until_approval(): void
    {
        $review = Review::factory()->published()->rating(5)->create([
            'place_id' => $this->place->id,
            'user_id' => $this->user->id,
            'body' => 'تجربة ممتازة من أول لآخر',
        ]);
        $this->place->forceFill(['rating_avg' => 5, 'rating_count' => 1])->save();

        $this->actingAs($this->user, 'sanctum')
            ->putJson("/api/v1/reviews/{$review->id}", $this->payload(['rating' => 2, 'body' => 'الخدمة اتغيّرت للأسوأ']), self::AR)
            ->assertOk()
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.rating', 5)
            ->assertJsonPath('data.edit.status', 'pending')
            ->assertJsonPath('data.edit.payload.rating', 2);

        $review->refresh();
        $this->assertSame(5, $review->rating);
        $this->assertSame('تجربة ممتازة من أول لآخر', $review->body);
        $this->assertSame('pending', $review->edit_status);
        $this->assertSame(5.0, (float) $this->place->refresh()->rating_avg);

        // القايمة العامة لسه بالقيم المنشورة
        $this->getJson($this->reviewUrl(), self::AR)
            ->assertJsonPath('data.0.rating', 5)
            ->assertJsonPath('data.0.body', 'تجربة ممتازة من أول لآخر');
    }

    public function test_a_hidden_review_cannot_be_edited(): void
    {
        $review = Review::factory()->hidden()->create(['place_id' => $this->place->id, 'user_id' => $this->user->id]);

        $this->actingAs($this->user, 'sanctum')
            ->putJson("/api/v1/reviews/{$review->id}", $this->payload(), self::AR)
            ->assertForbidden();
    }

    public function test_somebody_elses_review_is_not_found_for_edit_and_delete(): void
    {
        $review = Review::factory()->published()->create(['place_id' => $this->place->id]);

        $user = $this->actingAs($this->user, 'sanctum');

        $user->putJson("/api/v1/reviews/{$review->id}", $this->payload(), self::AR)
            ->assertNotFound()->assertJsonPath('code', 'NOT_FOUND');
        $user->deleteJson("/api/v1/reviews/{$review->id}", [], self::AR)->assertNotFound();

        $this->assertTrue(Review::query()->whereKey($review->id)->exists());
    }

    // ───────────────────────── الحذف ─────────────────────────

    public function test_deleting_a_published_review_removes_it_and_recomputes_the_average(): void
    {
        $review = Review::factory()->published()->rating(1)->create(['place_id' => $this->place->id, 'user_id' => $this->user->id]);
        Review::factory()->published()->rating(5)->create(['place_id' => $this->place->id]);
        PlaceRating::recalculate($this->place->id);
        $this->assertSame(3.0, (float) $this->place->refresh()->rating_avg);

        $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/v1/reviews/{$review->id}", [], self::AR)
            ->assertNoContent();

        $this->assertFalse(Review::query()->whereKey($review->id)->exists());

        $this->place->refresh();
        $this->assertSame(5.0, (float) $this->place->rating_avg);
        $this->assertSame(1, (int) $this->place->rating_count);
    }

    public function test_deleting_a_review_deletes_its_photos_and_the_files(): void
    {
        Storage::fake('local');
        config(['wain.photos.original_disk' => 'local']);

        Storage::disk('local')->put('review-originals/1/a.jpg', 'x');
        $review = Review::factory()->create(['place_id' => $this->place->id, 'user_id' => $this->user->id]);
        $photo = ReviewPhoto::query()->create([
            'review_id' => $review->id,
            'user_id' => $this->user->id,
            'path' => 'review-originals/1/a.jpg',
            'status' => 'ready',
            'created_at' => now(),
        ]);

        $this->actingAs($this->user, 'sanctum')->deleteJson("/api/v1/reviews/{$review->id}", [], self::AR)->assertNoContent();

        $this->assertFalse(ReviewPhoto::query()->whereKey($photo->id)->exists());
        Storage::disk('local')->assertMissing('review-originals/1/a.jpg');
    }

    // ───────────────────────── رفع الصور ─────────────────────────

    public function test_a_photo_upload_returns_an_id_and_processes_in_the_background(): void
    {
        Storage::fake('local');
        config(['wain.photos.original_disk' => 'local']);
        Bus::fake([ProcessReviewPhoto::class]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->post('/api/v1/uploads/photos', [
                'context' => 'review',
                'photo' => UploadedFile::fake()->image('menu.jpg', 1200, 800),
            ], self::AR + ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'processing');

        $photo = ReviewPhoto::query()->findOrFail($response->json('data.id'));
        $this->assertSame($this->user->id, $photo->user_id);
        $this->assertNull($photo->review_id);
        Storage::disk('local')->assertExists($photo->path);
        Bus::assertDispatched(ProcessReviewPhoto::class, fn ($job) => $job->photoId === $photo->id);
    }

    public function test_the_upload_job_makes_three_webp_variants(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        config(['wain.photos.original_disk' => 'local', 'wain.photos.disk' => 'public']);

        $this->actingAs($this->user, 'sanctum')
            ->post('/api/v1/uploads/photos', [
                'context' => 'review',
                'photo' => UploadedFile::fake()->image('plate.jpg', 2000, 1500),
            ], ['Accept' => 'application/json'])
            ->assertCreated();

        // QUEUE_CONNECTION=sync في التستات: اتعالجت فوراً
        $photo = ReviewPhoto::query()->firstOrFail();
        $this->assertSame('ready', $photo->status);
        $this->assertSame(['thumb', 'medium', 'large'], array_keys($photo->variants));
        $this->assertNotNull($photo->variantUrl('thumb'));
        $this->assertStringStartsWith('reviews/'.$this->user->id.'/', $photo->variants['thumb']);
        Storage::disk('public')->assertExists($photo->variants['large']);
    }

    public function test_an_upload_needs_a_verified_email_and_an_image(): void
    {
        $this->actingAs(User::factory()->unverified()->create(), 'sanctum')
            ->postJson('/api/v1/uploads/photos', ['context' => 'review'], self::AR)
            ->assertForbidden()
            ->assertJsonPath('code', 'EMAIL_NOT_VERIFIED');

        $this->actingAs($this->user, 'sanctum')
            ->post('/api/v1/uploads/photos', [
                'context' => 'review',
                'photo' => UploadedFile::fake()->create('notes.pdf', 100, 'application/pdf'),
            ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('photo');
    }

    public function test_too_many_unattached_uploads_hit_the_daily_limit(): void
    {
        foreach (range(1, 15) as $i) {
            ReviewPhoto::query()->create(['user_id' => $this->user->id, 'path' => "x/{$i}.jpg", 'status' => 'ready', 'created_at' => now()]);
        }

        Storage::fake('local');
        config(['wain.photos.original_disk' => 'local']);
        Bus::fake([ProcessReviewPhoto::class]);

        $this->actingAs($this->user, 'sanctum')
            ->post('/api/v1/uploads/photos', [
                'context' => 'review',
                'photo' => UploadedFile::fake()->image('one-more.jpg'),
            ], ['Accept' => 'application/json'])
            ->assertStatus(429)
            ->assertJsonPath('code', 'DAILY_LIMIT_REACHED');
    }

    public function test_an_unattached_photo_is_removed_after_a_day_but_a_pending_edit_keeps_its_photos(): void
    {
        Storage::fake('local');
        config(['wain.photos.original_disk' => 'local']);

        $stale = ReviewPhoto::query()->create(['user_id' => $this->user->id, 'path' => 'x/stale.jpg', 'status' => 'ready', 'created_at' => now()->subHours(30)]);
        $fresh = ReviewPhoto::query()->create(['user_id' => $this->user->id, 'path' => 'x/fresh.jpg', 'status' => 'ready', 'created_at' => now()->subHours(2)]);
        $held = ReviewPhoto::query()->create(['user_id' => $this->user->id, 'path' => 'x/held.jpg', 'status' => 'ready', 'created_at' => now()->subHours(40)]);

        Review::factory()->withPendingEdit(['rating' => 3, 'body' => null, 'visited_at' => null, 'attributes' => [], 'photo_ids' => [$held->id]])
            ->create(['place_id' => $this->place->id, 'user_id' => $this->user->id]);

        $this->artisan('reviews:prune-photos')->assertSuccessful();

        $this->assertFalse(ReviewPhoto::query()->whereKey($stale->id)->exists());
        $this->assertTrue(ReviewPhoto::query()->whereKey($fresh->id)->exists());
        $this->assertTrue(ReviewPhoto::query()->whereKey($held->id)->exists());
    }
}
