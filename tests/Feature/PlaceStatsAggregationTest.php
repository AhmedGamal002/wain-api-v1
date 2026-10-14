<?php

namespace Tests\Feature;

use App\Models\Place;
use App\Models\PlaceEvent;
use App\Support\PlaceStatsAggregator;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * التجميع الليلي لـ `place_events` في `place_stats_daily` (03 القسم 2، 06 القسم 4) ومسح الخام بعد 90 يوم.
 */
class PlaceStatsAggregationTest extends TestCase
{
    use RefreshDatabase;

    private const DEVICE = '3f6c1c2e-8c1a-4b6f-9d55-2f5b8c0e7a11';

    protected function setUp(): void
    {
        parent::setUp();

        // الساعة 3:25 الفجر بتوقيت القاهرة، 7 ديسمبر
        Carbon::setTestNow(Carbon::parse('2026-12-07 03:25', 'Africa/Cairo'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** حدث في وقت بتوقيت القاهرة (بيتخزّن UTC). */
    private function event(Place $place, string $type, string $cairoTime, ?string $device = self::DEVICE): void
    {
        DB::table('place_events')->insert([
            'place_id' => $place->id,
            'type' => $type,
            'device_id' => $device,
            'created_at' => Carbon::parse($cairoTime, 'Africa/Cairo')->utc(),
        ]);
    }

    /**
     * @return array<string, int> نوع => عدد، ليوم ومكان
     */
    private function stats(Place $place, string $date): array
    {
        return DB::table('place_stats_daily')
            ->where('place_id', $place->id)
            ->where('date', $date)
            ->pluck('count', 'type')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    public function test_a_cairo_day_is_counted_per_place_and_type(): void
    {
        $a = Place::factory()->create();
        $b = Place::factory()->create();

        $this->event($a, 'view', '2026-12-06 09:00');
        $this->event($a, 'view', '2026-12-06 21:30', '9b1d2f4a-1c3e-4f6a-8b7c-0d9e8f7a6b5c');
        $this->event($a, 'directions', '2026-12-06 12:00');
        $this->event($b, 'call', '2026-12-06 12:00');

        $this->assertTrue(app(PlaceStatsAggregator::class)->aggregateDay('2026-12-06'));

        $this->assertSame(['view' => 2, 'directions' => 1], $this->stats($a, '2026-12-06'));
        $this->assertSame(['call' => 1], $this->stats($b, '2026-12-06'));
    }

    public function test_the_day_edges_follow_cairo_midnight_not_utc(): void
    {
        $place = Place::factory()->create();

        // 00:00 القاهرة = 22:00 UTC اليوم اللي قبله (شتا، +2)
        $this->event($place, 'view', '2026-12-05 23:59:59');
        $this->event($place, 'view', '2026-12-06 00:00:00');
        $this->event($place, 'view', '2026-12-06 23:59:59');
        $this->event($place, 'view', '2026-12-07 00:00:00');

        $aggregator = app(PlaceStatsAggregator::class);
        $aggregator->aggregateDay('2026-12-05');
        $aggregator->aggregateDay('2026-12-06');
        $aggregator->aggregateDay('2026-12-07');

        $this->assertSame(['view' => 1], $this->stats($place, '2026-12-05'));
        $this->assertSame(['view' => 2], $this->stats($place, '2026-12-06'));
        $this->assertSame(['view' => 1], $this->stats($place, '2026-12-07'));
    }

    public function test_the_day_the_clocks_go_back_is_25_hours_long(): void
    {
        $place = Place::factory()->create();

        // الخميس 29 أكتوبر 2026: الساعة بترجع من 24:00 لـ 23:00، فـ 23:30 بيتكرر مرتين (UTC 20:30 و21:30)
        DB::table('place_events')->insert([
            ['place_id' => $place->id, 'type' => 'view', 'device_id' => self::DEVICE, 'created_at' => '2026-10-29 20:30:00'],
            ['place_id' => $place->id, 'type' => 'view', 'device_id' => self::DEVICE, 'created_at' => '2026-10-29 21:30:00'],
            // 00:30 الجمعة بتوقيت القاهرة (شتا) = 22:30 UTC: اليوم اللي بعده
            ['place_id' => $place->id, 'type' => 'view', 'device_id' => self::DEVICE, 'created_at' => '2026-10-29 22:30:00'],
        ]);

        $aggregator = app(PlaceStatsAggregator::class);
        $aggregator->aggregateDay('2026-10-29', now()->setDate(2026, 11, 1));
        $aggregator->aggregateDay('2026-10-30', now()->setDate(2026, 11, 1));

        $this->assertSame(['view' => 2], $this->stats($place, '2026-10-29'));
        $this->assertSame(['view' => 1], $this->stats($place, '2026-10-30'));
    }

    public function test_aggregating_again_gives_the_same_numbers_and_picks_up_late_events(): void
    {
        $place = Place::factory()->create();
        $aggregator = app(PlaceStatsAggregator::class);

        $this->event($place, 'call', '2026-12-06 10:00');
        $aggregator->aggregateDay('2026-12-06');
        $aggregator->aggregateDay('2026-12-06');

        $this->assertSame(['call' => 1], $this->stats($place, '2026-12-06'));

        // حدث اتأخّر في الـ queue لحد بعد التجميع الأول
        $this->event($place, 'call', '2026-12-06 23:58');
        $aggregator->aggregateDay('2026-12-06');

        $this->assertSame(['call' => 2], $this->stats($place, '2026-12-06'));
    }

    public function test_the_default_run_covers_yesterday_and_the_day_before_but_not_today(): void
    {
        $place = Place::factory()->create();

        $this->event($place, 'view', '2026-12-05 12:00');
        $this->event($place, 'view', '2026-12-06 12:00');
        $this->event($place, 'view', '2026-12-04 12:00');
        // 3:00 الفجر النهارده: اليوم لسه ما خلصش
        $this->event($place, 'view', '2026-12-07 03:00');

        $this->artisan('places:aggregate-stats')
            ->expectsOutputToContain('Aggregated 2026-12-06')
            ->expectsOutputToContain('Aggregated 2026-12-05')
            ->assertSuccessful();

        $this->assertSame(['view' => 1], $this->stats($place, '2026-12-06'));
        $this->assertSame(['view' => 1], $this->stats($place, '2026-12-05'));
        $this->assertSame([], $this->stats($place, '2026-12-04'));
        $this->assertSame([], $this->stats($place, '2026-12-07'));
    }

    public function test_a_single_day_and_a_longer_range_can_be_asked_for(): void
    {
        $place = Place::factory()->create();
        $this->event($place, 'view', '2026-12-01 12:00');
        $this->event($place, 'view', '2026-12-03 12:00');

        $this->artisan('places:aggregate-stats', ['--date' => '2026-12-01'])->assertSuccessful();
        $this->assertSame(['view' => 1], $this->stats($place, '2026-12-01'));
        $this->assertSame([], $this->stats($place, '2026-12-03'));

        $this->artisan('places:aggregate-stats', ['--days' => 7])->assertSuccessful();
        $this->assertSame(['view' => 1], $this->stats($place, '2026-12-03'));
    }

    public function test_a_bad_date_is_refused(): void
    {
        $this->artisan('places:aggregate-stats', ['--date' => '06/12/2026'])->assertExitCode(2);
        $this->artisan('places:aggregate-stats', ['--date' => '2026-02-31'])->assertExitCode(2);
    }

    public function test_a_day_past_the_retention_is_not_rebuilt_from_half_deleted_events(): void
    {
        $place = Place::factory()->create();
        DB::table('place_stats_daily')->insert(['place_id' => $place->id, 'date' => '2026-08-30', 'type' => 'view', 'count' => 40]);
        // اللي فضل من اليوم ده بعد المسح (الأقدم اتمسح) حدث واحد
        $this->event($place, 'view', '2026-08-30 23:00');

        $this->assertFalse(app(PlaceStatsAggregator::class)->aggregateDay('2026-08-30'));

        $this->assertSame(['view' => 40], $this->stats($place, '2026-08-30'));
    }

    public function test_a_place_with_no_events_that_day_gets_no_rows(): void
    {
        $place = Place::factory()->create();

        app(PlaceStatsAggregator::class)->aggregateDay('2026-12-06');

        $this->assertSame(0, DB::table('place_stats_daily')->where('place_id', $place->id)->count());
    }

    public function test_the_prune_drops_raw_events_past_90_days_and_keeps_the_stats(): void
    {
        $place = Place::factory()->create();

        $this->event($place, 'view', '2026-12-06 10:00');
        DB::table('place_events')->insert([
            ['place_id' => $place->id, 'type' => 'view', 'device_id' => self::DEVICE, 'created_at' => now()->subDays(91)],
            ['place_id' => $place->id, 'type' => 'call', 'device_id' => self::DEVICE, 'created_at' => now()->subDays(120)],
            ['place_id' => $place->id, 'type' => 'view', 'device_id' => self::DEVICE, 'created_at' => now()->subDays(89)],
        ]);
        DB::table('place_stats_daily')->insert(['place_id' => $place->id, 'date' => '2026-08-01', 'type' => 'view', 'count' => 12]);

        $this->artisan('events:prune')->expectsOutputToContain('Deleted 2 place events')->assertSuccessful();

        $this->assertSame(2, PlaceEvent::query()->count());
        $this->assertSame(['view' => 12], $this->stats($place, '2026-08-01'));
    }

    public function test_the_aggregation_runs_before_the_prune_every_night(): void
    {
        $events = collect(app(Schedule::class)->events());

        $at = fn (string $name) => $events->first(fn ($event) => str_contains((string) $event->command, $name))?->expression;

        $this->assertSame('25 3 * * *', $at('places:aggregate-stats'));
        $this->assertSame('45 3 * * *', $at('events:prune'));
    }
}
