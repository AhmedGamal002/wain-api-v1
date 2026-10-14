<?php

namespace Tests\Feature;

use App\Models\Place;
use App\Models\User;
use App\Support\PlaceTransitionException;
use App\Support\PlaceTransitions;
use Database\Seeders\AttributeSeeder;
use Database\Seeders\CategorySeeder;
use Database\Seeders\CityAreaSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * انتقالات حالة المكان (03 القسم 2 «جدول الانتقالات»): القواعد في `PlaceTransitions`، والزرار في اللوحة بينادي
 * عليها بس.
 */
class PlaceTransitionsTest extends TestCase
{
    use RefreshDatabase;

    private PlaceTransitions $transitions;

    private User $admin;

    private User $moderator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleSeeder::class, SettingSeeder::class, CategorySeeder::class, CityAreaSeeder::class, AttributeSeeder::class]);

        $this->transitions = new PlaceTransitions;
        $this->admin = User::factory()->create()->assignRole('admin');
        $this->moderator = User::factory()->create()->assignRole('moderator');
        $this->actingAs($this->admin);
    }

    public function test_submit_moves_a_complete_draft_to_pending_and_clears_the_old_reason(): void
    {
        $place = Place::factory()->complete()->create(['status_reason' => 'سبب قديم']);

        $this->transitions->submit($place, $this->moderator);

        $place->refresh();
        $this->assertSame(Place::STATUS_PENDING, $place->status);
        $this->assertNull($place->status_reason);
    }

    public function test_submit_of_an_incomplete_draft_names_what_is_missing(): void
    {
        $place = Place::factory()->create();

        try {
            $this->transitions->submit($place, $this->moderator);
            $this->fail('الإرسال المفروض يترفض');
        } catch (PlaceTransitionException $e) {
            $this->assertStringContainsString('صورة الغلاف', $e->getMessage());
            $this->assertStringContainsString('المواعيد', $e->getMessage());
        }

        $this->assertSame(Place::STATUS_DRAFT, $place->refresh()->status);
    }

    public function test_publish_sets_published_at_once_and_only_admin_can_publish(): void
    {
        $place = Place::factory()->complete()->status(Place::STATUS_PENDING)->create();

        try {
            $this->transitions->publish($place, $this->moderator);
            $this->fail('الـ Moderator ما ينفعش ينشر');
        } catch (AuthorizationException) {
            // متوقع
        }
        $this->assertSame(Place::STATUS_PENDING, $place->refresh()->status);

        $this->transitions->publish($place, $this->admin);

        $place->refresh();
        $this->assertSame(Place::STATUS_ACTIVE, $place->status);
        $this->assertNotNull($place->published_at);
    }

    public function test_publish_of_an_incomplete_place_is_refused(): void
    {
        $place = Place::factory()->status(Place::STATUS_PENDING)->create();

        $this->expectException(PlaceTransitionException::class);
        $this->expectExceptionMessage('ما ينفعش يتنشر. ناقص:');

        $this->transitions->publish($place, $this->admin);
    }

    public function test_reject_requires_a_reason_and_keeps_it_in_status_reason(): void
    {
        $place = Place::factory()->complete()->status(Place::STATUS_PENDING)->create();

        try {
            $this->transitions->reject($place, $this->admin, 'nonsense');
            $this->fail('السبب لازم يكون من القايمة');
        } catch (PlaceTransitionException) {
            $this->assertSame(Place::STATUS_PENDING, $place->refresh()->status);
        }

        $this->transitions->reject($place, $this->admin, 'weak_photos', 'الغلاف مش واضح');

        $place->refresh();
        $this->assertSame(Place::STATUS_REJECTED, $place->status);
        $this->assertSame('صور ضعيفة: الغلاف مش واضح', $place->status_reason);
    }

    public function test_the_other_reason_needs_details(): void
    {
        $place = Place::factory()->published()->create();

        $this->expectException(PlaceTransitionException::class);

        $this->transitions->hide($place, $this->admin, 'other', '   ');
    }

    public function test_hide_then_show_round_trip(): void
    {
        $place = Place::factory()->published()->create();
        $publishedAt = $place->refresh()->published_at;

        $this->transitions->hide($place, $this->admin, 'renovation');
        $this->assertSame(Place::STATUS_HIDDEN, $place->refresh()->status);
        $this->assertSame('تجديدات مؤقتة', $place->status_reason);

        $this->transitions->show($place, $this->admin);
        $place->refresh();
        $this->assertSame(Place::STATUS_ACTIVE, $place->status);
        $this->assertNull($place->status_reason);
        $this->assertEquals($publishedAt, $place->published_at, 'الإخفاء والإظهار ما بيغيّروش published_at');
    }

    public function test_show_of_a_hidden_place_that_lost_a_condition_is_refused(): void
    {
        $place = Place::factory()->published()->status(Place::STATUS_HIDDEN)->create();
        $place->forceFill(['cover_photo_id' => null])->saveQuietly();

        $this->expectException(PlaceTransitionException::class);
        $this->expectExceptionMessage('صورة الغلاف');

        $this->transitions->show($place->refresh(), $this->admin);
    }

    public function test_hide_only_works_on_published_places(): void
    {
        $place = Place::factory()->complete()->create();

        $this->expectException(PlaceTransitionException::class);

        $this->transitions->hide($place, $this->admin, 'complaint');
    }

    public function test_close_resets_the_tier_and_rejects_a_future_date(): void
    {
        $place = Place::factory()->published()->create([
            'listing_tier' => 'premium',
            'tier_starts_at' => now()->subDay(),
            'tier_ends_at' => now()->addMonth(),
        ]);

        try {
            $this->transitions->close($place, $this->admin, Carbon::parse('+3 days', 'Africa/Cairo'));
            $this->fail('تاريخ القفل في المستقبل');
        } catch (PlaceTransitionException) {
            $this->assertSame(Place::STATUS_ACTIVE, $place->refresh()->status);
        }

        $this->transitions->close($place, $this->admin, Carbon::now('Africa/Cairo'), 'اتقفل للأبد');

        $place->refresh();
        $this->assertSame(Place::STATUS_CLOSED, $place->status);
        $this->assertSame('free', $place->listing_tier);
        $this->assertNull($place->tier_ends_at);
        $this->assertSame(3, $place->tier_rank);
        $this->assertSame(Carbon::now('Africa/Cairo')->toDateString(), $place->closed_at->toDateString());
    }

    public function test_reopen_returns_a_closed_place_to_active(): void
    {
        $place = Place::factory()->published()->create();
        $this->transitions->close($place, $this->admin, now('Africa/Cairo'));

        $this->transitions->reopen($place->refresh(), $this->admin);

        $place->refresh();
        $this->assertSame(Place::STATUS_ACTIVE, $place->status);
        $this->assertNull($place->closed_at);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_set_tier_stores_cairo_days_in_utc_and_syncs_tier_rank(): void
    {
        // اليوم هو يوم بداية الباقة (الباقة بتتسجّل يوم بدايتها)
        Carbon::setTestNow(Carbon::parse('2026-11-22 12:00', 'Africa/Cairo'));
        $place = Place::factory()->published()->create();

        $this->transitions->setTier(
            $place,
            $this->admin,
            'featured',
            Carbon::parse('2026-11-22', 'Africa/Cairo'),
            Carbon::parse('2026-12-22', 'Africa/Cairo'),
            'INV-1042',
        );

        $place->refresh();
        $this->assertSame('featured', $place->listing_tier);
        $this->assertSame(2, $place->tier_rank);
        // القاهرة UTC+2 في نوفمبر: بداية اليوم 22:00 UTC اللي قبله، ونهايته 21:59:59 UTC
        $this->assertSame('2026-11-21 22:00:00', $place->tier_starts_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-12-22 21:59:59', $place->tier_ends_at->utc()->format('Y-m-d H:i:s'));

        $log = Activity::query()->where('subject_id', $place->id)->where('log_name', Place::LOG_NAME)->latest('id')->first();
        $this->assertSame('INV-1042', $log->properties['note'] ?? null);
    }

    public function test_set_tier_validates_dates_and_free_clears_them(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-12-01 12:00', 'Africa/Cairo'));
        $place = Place::factory()->published()->create();

        try {
            $this->transitions->setTier($place, $this->admin, 'premium');
            $this->fail('التواريخ إلزامية');
        } catch (PlaceTransitionException) {
        }

        try {
            $this->transitions->setTier($place, $this->admin, 'premium', Carbon::parse('2026-12-01'), Carbon::parse('2026-12-01'));
            $this->fail('النهاية بعد البداية');
        } catch (PlaceTransitionException) {
        }

        $this->transitions->setTier($place, $this->admin, 'premium', Carbon::parse('2026-12-01'), Carbon::parse('2026-12-31'));
        $this->transitions->setTier($place->refresh(), $this->admin, 'free');

        $place->refresh();
        $this->assertSame('free', $place->listing_tier);
        $this->assertNull($place->tier_starts_at);
        $this->assertNull($place->tier_ends_at);
        $this->assertSame(3, $place->tier_rank);
    }

    public function test_set_tier_rejects_a_start_date_after_today_and_accepts_today_and_the_past(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-01 03:00', 'Africa/Cairo'));
        $place = Place::factory()->published()->create();

        // بكرة بالقاهرة: الباقة كانت هتتفعّل فوراً قبل ميعادها
        try {
            $this->transitions->setTier($place, $this->admin, 'premium', Carbon::parse('2026-11-02', 'Africa/Cairo'), Carbon::parse('2026-11-30', 'Africa/Cairo'));
            $this->fail('بداية في المستقبل');
        } catch (PlaceTransitionException $e) {
            $this->assertStringContainsString('يوم بدايتها', $e->getMessage());
        }

        $place->refresh();
        $this->assertSame('free', $place->listing_tier);
        $this->assertNull($place->tier_starts_at);

        // النهارده (بعد منتصف الليل بتوقيت القاهرة، لسه 31 أكتوبر بـ UTC) مسموح
        $this->transitions->setTier($place, $this->admin, 'featured', Carbon::parse('2026-11-01', 'Africa/Cairo'), Carbon::parse('2026-11-30', 'Africa/Cairo'));
        $this->assertSame('featured', $place->refresh()->listing_tier);

        // والماضي مسموح (فاتورة اتأخر تسجيلها)
        $this->transitions->setTier($place->refresh(), $this->admin, 'premium', Carbon::parse('2026-10-20', 'Africa/Cairo'), Carbon::parse('2026-11-20', 'Africa/Cairo'));
        $this->assertSame('premium', $place->refresh()->listing_tier);
    }

    public function test_moderator_cannot_set_tier_hide_close_or_delete(): void
    {
        $place = Place::factory()->published()->create();

        foreach ([
            fn () => $this->transitions->setTier($place, $this->moderator, 'featured', now(), now()->addDay()),
            fn () => $this->transitions->hide($place, $this->moderator, 'complaint'),
            fn () => $this->transitions->close($place, $this->moderator, now()),
            fn () => $this->transitions->delete($place, $this->moderator),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('الـ Moderator ما ينفعش');
            } catch (AuthorizationException) {
            }
        }

        $this->assertSame(Place::STATUS_ACTIVE, $place->refresh()->status);
        $this->assertFalse($place->trashed());
    }

    public function test_verify_stamps_verified_at(): void
    {
        $place = Place::factory()->published()->create();
        $this->assertNull($place->verified_at);

        $this->transitions->verify($place, $this->moderator);

        $this->assertNotNull($place->refresh()->verified_at);
    }

    public function test_delete_then_restore_brings_the_place_back_hidden(): void
    {
        $place = Place::factory()->published()->create();

        $this->transitions->delete($place, $this->admin);
        $this->assertTrue($place->refresh()->trashed());

        $this->transitions->restore($place, $this->admin);

        $place->refresh();
        $this->assertFalse($place->trashed());
        $this->assertSame(Place::STATUS_HIDDEN, $place->status);
        $this->assertNotNull($place->status_reason);
    }

    public function test_restore_after_30_days_is_refused(): void
    {
        $place = Place::factory()->published()->create();
        $this->transitions->delete($place, $this->admin);
        Place::withTrashed()->whereKey($place->id)->update(['deleted_at' => now()->subDays(Place::PURGE_AFTER_DAYS + 1)]);

        $this->expectException(PlaceTransitionException::class);

        $this->transitions->restore(Place::withTrashed()->findOrFail($place->id), $this->admin);
    }

    public function test_actions_on_a_deleted_place_are_refused(): void
    {
        $place = Place::factory()->published()->create();
        $this->transitions->delete($place, $this->admin);

        $this->expectException(PlaceTransitionException::class);

        $this->transitions->hide($place->refresh(), $this->admin, 'complaint');
    }

    public function test_publish_many_skips_the_incomplete_and_reports_what_they_miss(): void
    {
        $good = Place::factory()->complete()->status(Place::STATUS_PENDING)->count(2)->create();
        $bad = Place::factory()->status(Place::STATUS_PENDING)->create();

        $result = $this->transitions->publishMany($good->push($bad), $this->admin);

        $this->assertSame(2, $result['published']);
        $this->assertCount(1, $result['skipped']);
        $this->assertSame($bad->id, $result['skipped'][0]['place']->id);
        $this->assertContains('صورة الغلاف', $result['skipped'][0]['missing']);
        $this->assertSame(Place::STATUS_PENDING, $bad->refresh()->status);
    }

    public function test_status_changes_are_in_the_activity_log_with_old_and_new_values(): void
    {
        $place = Place::factory()->published()->create();

        $this->transitions->hide($place, $this->admin, 'complaint');

        $log = Activity::query()
            ->where('subject_id', $place->id)
            ->where('log_name', Place::LOG_NAME)
            ->latest('id')
            ->first();

        $this->assertSame($this->admin->id, $log->causer_id);
        $this->assertSame(Place::STATUS_HIDDEN, $log->properties['attributes']['status']);
        $this->assertSame(Place::STATUS_ACTIVE, $log->properties['old']['status']);
    }
}
