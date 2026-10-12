<?php

namespace Tests\Unit;

use App\Support\OpeningHours;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * منطق «مفتوح دلوقتي» الصافي (03 القسم 7.1، 4.4). الدقيقة 0 = السبت 00:00.
 */
class OpeningHoursTest extends TestCase
{
    private const SAT = 0;

    private const SUN = 1440;

    private const MON = 2880;

    private const TUE = 4320;

    private const FRI = 8640;

    private static function m(int $dayStart, string $time): int
    {
        [$h, $min] = explode(':', $time);

        return $dayStart + (int) $h * 60 + (int) $min;
    }

    /**
     * @return array<string, mixed>
     */
    private static function everyDay(array $day): array
    {
        return array_fill_keys(OpeningHours::DAYS, $day);
    }

    public function test_plain_slot_includes_the_opening_minute_and_excludes_the_closing_one(): void
    {
        $hours = self::everyDay(['state' => 'open', 'slots' => [['09:00', '23:30']]]);

        $this->assertFalse(OpeningHours::isOpenAt($hours, self::m(self::SAT, '08:59')));
        $this->assertTrue(OpeningHours::isOpenAt($hours, self::m(self::SAT, '09:00')));
        $this->assertTrue(OpeningHours::isOpenAt($hours, self::m(self::SAT, '23:29')));
        $this->assertFalse(OpeningHours::isOpenAt($hours, self::m(self::SAT, '23:30')));
    }

    public function test_slot_past_midnight_keeps_the_place_open_after_midnight_of_the_next_day(): void
    {
        // السبت 18:00 لـ 02:00 تاني يوم
        $hours = self::everyDay(['state' => 'closed']);
        $hours['sat'] = ['state' => 'open', 'slots' => [['18:00', '02:00']]];

        $this->assertTrue(OpeningHours::isOpenAt($hours, self::m(self::SAT, '23:00')));
        $this->assertTrue(OpeningHours::isOpenAt($hours, self::m(self::SUN, '01:59')), 'still open from yesterday');
        $this->assertFalse(OpeningHours::isOpenAt($hours, self::m(self::SUN, '02:00')));
        $this->assertFalse(OpeningHours::isOpenAt($hours, self::m(self::SUN, '19:00')), 'sunday itself is closed');
    }

    public function test_friday_night_slot_wraps_into_saturday_morning(): void
    {
        $hours = self::everyDay(['state' => 'closed']);
        $hours['fri'] = ['state' => 'open', 'slots' => [['13:00', '01:00']]];

        $this->assertTrue(OpeningHours::isOpenAt($hours, self::m(self::FRI, '23:30')));
        $this->assertTrue(OpeningHours::isOpenAt($hours, self::m(self::SAT, '00:30')), 'saturday 00:30 is still friday night');
        $this->assertFalse(OpeningHours::isOpenAt($hours, self::m(self::SAT, '01:00')));
    }

    public function test_closing_at_midnight_means_the_end_of_the_day(): void
    {
        $hours = self::everyDay(['state' => 'open', 'slots' => [['12:00', '00:00']]]);

        $this->assertTrue(OpeningHours::isOpenAt($hours, self::m(self::MON, '23:59')));
        // الفترة من 12:00 لـ 00:00 بتخلص عند نص الليل بالظبط، والصبح مقفول لحد 12:00
        $this->assertFalse(OpeningHours::isOpenAt($hours, self::m(self::TUE, '00:00')));
        $this->assertFalse(OpeningHours::isOpenAt($hours, self::m(self::TUE, '11:59')));
        $this->assertTrue(OpeningHours::isOpenAt($hours, self::m(self::TUE, '12:00')));
    }

    public function test_two_slots_in_a_day_with_a_break_between_them(): void
    {
        $hours = self::everyDay(['state' => 'open', 'slots' => [['09:00', '14:00'], ['18:00', '02:00']]]);

        $this->assertTrue(OpeningHours::isOpenAt($hours, self::m(self::MON, '10:00')));
        $this->assertFalse(OpeningHours::isOpenAt($hours, self::m(self::MON, '15:00')), 'afternoon break');
        $this->assertTrue(OpeningHours::isOpenAt($hours, self::m(self::MON, '20:00')));
        $this->assertTrue(OpeningHours::isOpenAt($hours, self::m(self::TUE, '01:00')));
    }

    public function test_24h_and_closed_days(): void
    {
        $hours = self::everyDay(['state' => 'closed']);
        $hours['mon'] = ['state' => '24h'];

        $this->assertTrue(OpeningHours::isOpenAt($hours, self::m(self::MON, '00:00')));
        $this->assertTrue(OpeningHours::isOpenAt($hours, self::m(self::MON, '23:59')));
        $this->assertFalse(OpeningHours::isOpenAt($hours, self::m(self::TUE, '00:00')));
        $this->assertFalse(OpeningHours::isOpenAt($hours, self::m(self::SUN, '12:00')));
    }

    public function test_empty_hours_are_unknown_not_closed(): void
    {
        $this->assertNull(OpeningHours::isOpenAt(null, 100));
        $this->assertNull(OpeningHours::isOpenAt([], 100));
    }

    public function test_intervals_split_at_the_end_of_the_week(): void
    {
        $hours = ['fri' => ['state' => 'open', 'slots' => [['22:00', '03:00']]]];

        $this->assertSame([[self::FRI + 22 * 60, OpeningHours::MINUTES_PER_WEEK], [0, 180]], OpeningHours::intervals($hours));
    }

    public function test_malformed_slots_are_ignored(): void
    {
        $hours = ['sat' => ['state' => 'open', 'slots' => [['9am', '5pm'], ['10:00'], 'x']]];

        $this->assertSame([], OpeningHours::intervals($hours));
        $this->assertFalse(OpeningHours::isOpenAt($hours, 600));
    }

    public function test_week_minute_starts_on_saturday(): void
    {
        // 2026-10-10 كان سبت، و2026-10-11 أحد، و2026-10-16 جمعة
        $this->assertSame(0, OpeningHours::weekMinute(Carbon::parse('2026-10-10 00:00')));
        $this->assertSame(self::SUN + 9 * 60 + 30, OpeningHours::weekMinute(Carbon::parse('2026-10-11 09:30')));
        $this->assertSame(OpeningHours::MINUTES_PER_WEEK - 1, OpeningHours::weekMinute(Carbon::parse('2026-10-16 23:59')));
    }
}
