<?php

namespace Tests\Feature;

use App\Models\Place;
use App\Models\User;
use App\Support\PlaceTierExpiry;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * job انتهاء الباقات (03 القسم 7.4، 4.9): المنتهي بيرجع free، والمنتهي بعد 7 أيام بيتنبّه عليه الأدمنز.
 */
class PlaceTierExpiryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleSeeder::class, SettingSeeder::class]);
        $this->admin = User::factory()->create()->assignRole('admin');

        // صباح 1 نوفمبر بتوقيت القاهرة
        Carbon::setTestNow(Carbon::parse('2026-11-01 03:00', 'Africa/Cairo'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** نهاية اليوم بتوقيت القاهرة، زي ما `PlaceTransitions::setTier` بيخزّنها. */
    private function endOfCairoDay(string $date): Carbon
    {
        return Carbon::parse($date, 'Africa/Cairo')->endOfDay()->utc();
    }

    private function paid(string $tier, ?string $endsOn, string $status = 'active', array $attributes = []): Place
    {
        return Place::factory()->published()->create([
            'status' => $status,
            'listing_tier' => $tier,
            'tier_starts_at' => $endsOn === null ? null : Carbon::parse('2026-10-01', 'Africa/Cairo')->startOfDay()->utc(),
            'tier_ends_at' => $endsOn === null ? null : $this->endOfCairoDay($endsOn),
            ...$attributes,
        ]);
    }

    /** @return list<string> */
    private function titles(User $user): array
    {
        return $user->notifications()->get()->map(fn ($n) => $n->data['title'] ?? '')->all();
    }

    public function test_an_expired_tier_goes_back_to_free_with_a_log_entry(): void
    {
        $place = $this->paid('premium', '2026-10-31');
        $this->assertSame(1, $place->tier_rank);

        $this->assertSame(1, (new PlaceTierExpiry)->expire());

        $place->refresh();
        $this->assertSame('free', $place->listing_tier);
        $this->assertSame(3, $place->tier_rank);
        $this->assertNull($place->tier_starts_at);
        $this->assertNull($place->tier_ends_at);

        $log = Activity::query()->where('subject_id', $place->id)->latest('id')->first();
        $this->assertNull($log->causer_id);
        $this->assertSame('انتهت مدة الباقة', $log->properties['note']);
        $this->assertSame('free', $log->properties['attributes']['listing_tier']);
    }

    public function test_a_tier_that_ends_today_stays_until_the_day_is_over(): void
    {
        // «إلى» = نهاية يوم 1 نوفمبر بتوقيت القاهرة، فالباقة شغالة طول اليوم
        $place = $this->paid('featured', '2026-11-01');

        $this->assertSame(0, (new PlaceTierExpiry)->expire());
        $this->assertSame('featured', $place->refresh()->listing_tier);

        Carbon::setTestNow(Carbon::parse('2026-11-02 00:00:01', 'Africa/Cairo'));

        $this->assertSame(1, (new PlaceTierExpiry)->expire());
        $this->assertSame('free', $place->refresh()->listing_tier);
    }

    public function test_it_leaves_running_free_and_renewed_tiers_alone(): void
    {
        $running = $this->paid('premium', '2026-12-31');
        $free = Place::factory()->published()->create();
        // مجدّدة: كانت هتنتهي وتم تمديدها
        $renewed = $this->paid('featured', '2026-10-30');
        $renewed->forceFill(['tier_ends_at' => $this->endOfCairoDay('2027-01-15')])->save();

        $this->assertSame(0, (new PlaceTierExpiry)->expire());

        $this->assertSame('premium', $running->refresh()->listing_tier);
        $this->assertSame('free', $free->refresh()->listing_tier);
        $this->assertSame('featured', $renewed->refresh()->listing_tier);
    }

    public function test_hidden_places_expire_too(): void
    {
        $hidden = $this->paid('premium', '2026-10-20', Place::STATUS_HIDDEN);

        $this->assertSame(1, (new PlaceTierExpiry)->expire());
        $this->assertSame('free', $hidden->refresh()->listing_tier);
        $this->assertSame(Place::STATUS_HIDDEN, $hidden->status);
    }

    public function test_expiring_clears_the_details_cache_so_the_badge_goes_away_at_once(): void
    {
        $place = $this->paid('premium', '2026-10-31');
        Cache::put(Place::detailsCacheKey($place->slug), ['stale' => true], 300);

        (new PlaceTierExpiry)->expire();

        $this->assertNull(Cache::get(Place::detailsCacheKey($place->slug)));
    }

    public function test_warns_the_active_admins_seven_days_ahead_and_only_then(): void
    {
        $inSeven = $this->paid('premium', '2026-11-08', attributes: ['name_ar' => 'سيلانترو']);
        $this->paid('featured', '2026-11-07');
        $this->paid('featured', '2026-11-09');
        $this->paid('premium', '2026-10-25');

        $otherAdmin = User::factory()->create()->assignRole('admin');
        $suspended = User::factory()->create(['status' => 'suspended'])->assignRole('admin');
        $moderator = User::factory()->create()->assignRole('moderator');

        $this->assertSame(1, (new PlaceTierExpiry)->warn());

        $expected = ['باقة «بريميوم» لمكان «سيلانترو» بتنتهي يوم 2026-11-08'];
        $this->assertSame($expected, $this->titles($this->admin));
        $this->assertSame($expected, $this->titles($otherAdmin));
        $this->assertSame([], $this->titles($suspended));
        $this->assertSame([], $this->titles($moderator));

        // الرابط بيفتح صفحة المكان في اللوحة
        $action = $this->admin->notifications()->first()->data['actions'][0];
        $this->assertStringContainsString('/admin/places/'.$inSeven->id, $action['url']);
    }

    public function test_the_command_expires_and_warns_in_one_run(): void
    {
        $this->paid('premium', '2026-10-31');
        $this->paid('featured', '2026-11-08');

        $this->artisan('places:expire-tiers')
            ->expectsOutputToContain('Expired 1 tier(s), warned about 1 tier(s)')
            ->assertSuccessful();

        $this->assertCount(1, $this->titles($this->admin));
    }
}
