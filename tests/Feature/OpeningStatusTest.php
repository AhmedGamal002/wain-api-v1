<?php

namespace Tests\Feature;

use App\Http\Resources\PlaceCardResource;
use App\Http\Resources\PlaceHoursResource;
use App\Models\Place;
use App\Support\OpeningStatus;
use App\Support\OpenNow;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * سطر الحالة `status_text` (03 القسم 7.1) بالعربي والإنجليزي، وبلوك hours (5.3).
 * الدقيقة 0 = السبت 00:00، فالاتنين = 2880 والخميس = 7200 والجمعة = 8640.
 */
class OpeningStatusTest extends TestCase
{
    private const SAT = 0;

    private const SUN = 1440;

    private const MON = 2880;

    private const TUE = 4320;

    private const THU = 7200;

    private const FRI = 8640;

    private static function at(int $dayStart, string $time): int
    {
        [$h, $m] = explode(':', $time);

        return $dayStart + (int) $h * 60 + (int) $m;
    }

    /**
     * @return array<string, mixed>
     */
    private static function daily(string $from, string $to): array
    {
        return array_fill_keys(
            ['sat', 'sun', 'mon', 'tue', 'wed', 'thu', 'fri'],
            ['state' => 'open', 'slots' => [[$from, $to]]],
        );
    }

    private function text(?array $hours, int $minute, string $locale = 'ar'): ?string
    {
        app()->setLocale($locale);

        return OpeningStatus::describe($hours, $minute)['text'] ?? null;
    }

    private function state(?array $hours, int $minute): ?string
    {
        return OpeningStatus::describe($hours, $minute)['state'] ?? null;
    }

    public function test_open_with_more_than_30_minutes_left(): void
    {
        $hours = self::daily('09:00', '23:30');

        $this->assertSame('مفتوح · بيقفل 11:30 م', $this->text($hours, self::at(self::MON, '12:00')));
        $this->assertSame('open', $this->state($hours, self::at(self::MON, '12:00')));
        // 31 دقيقة باقية لسه عادي
        $this->assertSame('open', $this->state($hours, self::at(self::MON, '22:59')));
    }

    public function test_closing_soon_when_30_minutes_or_less_are_left(): void
    {
        $hours = self::daily('09:00', '23:30');

        $this->assertSame('بيقفل قريب · 11:30 م', $this->text($hours, self::at(self::MON, '23:00')));
        $this->assertSame('closing_soon', $this->state($hours, self::at(self::MON, '23:00')));
        $this->assertSame('closing_soon', $this->state($hours, self::at(self::MON, '23:29')));
    }

    public function test_open_24_hours(): void
    {
        $hours = array_fill_keys(['sat', 'sun', 'mon', 'tue', 'wed', 'thu', 'fri'], ['state' => '24h']);

        $this->assertSame('مفتوح 24 ساعة', $this->text($hours, self::at(self::TUE, '03:00')));
        $this->assertSame('open_24h', $this->state($hours, self::at(self::TUE, '03:00')));
    }

    public function test_closed_and_opens_later_today(): void
    {
        $hours = self::daily('18:00', '02:00');

        $this->assertSame('مقفول · بيفتح 6:00 م', $this->text($hours, self::at(self::MON, '10:00')));
        $this->assertSame('closed', $this->state($hours, self::at(self::MON, '10:00')));
    }

    public function test_closed_and_opens_tomorrow(): void
    {
        $hours = self::daily('09:00', '23:30');

        $this->assertSame('مقفول · بيفتح بكرة 9:00 ص', $this->text($hours, self::at(self::MON, '23:45')));
    }

    public function test_closed_and_opens_in_two_days_or_more_names_the_weekday(): void
    {
        // مفتوح الخميس بس، والسبت الضهر مقفول
        $hours = array_fill_keys(['sat', 'sun', 'mon', 'tue', 'wed', 'fri'], ['state' => 'closed']);
        $hours['thu'] = ['state' => 'open', 'slots' => [['09:00', '17:00']]];

        $this->assertSame('مقفول · بيفتح الخميس 9:00 ص', $this->text($hours, self::at(self::SAT, '12:00')));
        $this->assertSame('Closed · opens Thursday 9:00 AM', $this->text($hours, self::at(self::SAT, '12:00'), 'en'));
    }

    public function test_after_midnight_the_place_is_still_open_with_yesterdays_closing_time(): void
    {
        $hours = array_fill_keys(['sat', 'sun', 'mon', 'tue', 'wed', 'thu', 'fri'], ['state' => 'closed']);
        $hours['sat'] = ['state' => 'open', 'slots' => [['18:00', '02:00']]];

        // الأحد 00:30: لسه من فترة السبت، والقفل 2:00 ص بعد ساعة ونص
        $this->assertSame('مفتوح · بيقفل 2:00 ص', $this->text($hours, self::at(self::SUN, '00:30')));
        $this->assertSame('بيقفل قريب · 2:00 ص', $this->text($hours, self::at(self::SUN, '01:45')));
        // بعد القفل: مقفول وبيفتح السبت الجاي (6 أيام بعد الأحد)
        $this->assertSame('مقفول · بيفتح السبت 6:00 م', $this->text($hours, self::at(self::SUN, '12:00')));
    }

    public function test_adjacent_periods_across_midnight_are_merged_into_one(): void
    {
        // السبت لحد 02:00 تاني يوم، والأحد بيفتح من 00:00 لحد 05:00: القفل الحقيقي 5:00 ص
        $hours = array_fill_keys(['sat', 'sun', 'mon', 'tue', 'wed', 'thu', 'fri'], ['state' => 'closed']);
        $hours['sat'] = ['state' => 'open', 'slots' => [['18:00', '02:00']]];
        $hours['sun'] = ['state' => 'open', 'slots' => [['02:00', '05:00']]];

        $this->assertSame('مفتوح · بيقفل 5:00 ص', $this->text($hours, self::at(self::SUN, '00:30')));
    }

    public function test_friday_night_slot_that_wraps_into_saturday(): void
    {
        $hours = array_fill_keys(['sat', 'sun', 'mon', 'tue', 'wed', 'thu', 'fri'], ['state' => 'closed']);
        $hours['fri'] = ['state' => 'open', 'slots' => [['13:00', '03:00']]];

        $this->assertSame('مفتوح · بيقفل 3:00 ص', $this->text($hours, self::at(self::FRI, '23:00')));
        $this->assertSame('مفتوح · بيقفل 3:00 ص', $this->text($hours, self::at(self::SAT, '00:30')));
        $this->assertSame('مقفول · بيفتح 1:00 م', $this->text($hours, self::at(self::FRI, '10:00')));
    }

    public function test_noon_and_midnight_use_twelve(): void
    {
        $this->assertSame('مفتوح · بيقفل 12:00 ص', $this->text(self::daily('12:00', '00:00'), self::at(self::MON, '15:00')));
        $this->assertSame('مقفول · بيفتح 12:00 م', $this->text(self::daily('12:00', '00:00'), self::at(self::MON, '10:00')));
        $this->assertSame('Open · closes 12:00 AM', $this->text(self::daily('12:00', '00:00'), self::at(self::MON, '15:00'), 'en'));
    }

    public function test_english_texts(): void
    {
        $hours = self::daily('09:00', '23:30');

        $this->assertSame('Open · closes 11:30 PM', $this->text($hours, self::at(self::MON, '12:00'), 'en'));
        $this->assertSame('Closing soon · 11:30 PM', $this->text($hours, self::at(self::MON, '23:10'), 'en'));
        $this->assertSame('Closed · opens tomorrow 9:00 AM', $this->text($hours, self::at(self::MON, '23:45'), 'en'));
        $this->assertSame('Closed · opens 9:00 AM', $this->text($hours, self::at(self::MON, '07:00'), 'en'));
    }

    public function test_all_days_closed_has_no_opening_time(): void
    {
        $hours = array_fill_keys(['sat', 'sun', 'mon', 'tue', 'wed', 'thu', 'fri'], ['state' => 'closed']);

        $this->assertSame('مقفول', $this->text($hours, self::at(self::MON, '12:00')));
        $this->assertFalse(OpeningStatus::describe($hours, self::at(self::MON, '12:00'))['is_open_now']);
    }

    public function test_empty_hours_have_no_status(): void
    {
        $this->assertNull(OpeningStatus::describe(null, 100));
        $this->assertNull(OpeningStatus::describe([], 100));
    }

    public function test_hours_resource_returns_the_documented_block(): void
    {
        app()->setLocale('ar');

        $place = new Place([
            'opening_hours' => [
                'sat' => ['state' => 'open', 'slots' => [['09:00', '23:30']]],
                'sun' => ['state' => '24h'],
                'mon' => ['state' => 'closed'],
            ],
        ]);

        $request = Request::create('/');
        // اللحظة بتتحدد صراحةً بالدقيقة (السبت 12:00) بدل الاعتماد على الساعة الحالية
        $request->attributes->set(PlaceCardResource::OPEN_NOW_ATTRIBUTE, new OpenNow(self::at(self::SAT, '12:00'), false));

        $block = (new PlaceHoursResource($place))->resolve($request);

        $this->assertSame(['is_open_now', 'state', 'status_text', 'is_ramadan', 'week'], array_keys($block));
        $this->assertTrue($block['is_open_now']);
        $this->assertSame('open', $block['state']);
        $this->assertSame('مفتوح · بيقفل 11:30 م', $block['status_text']);
        $this->assertFalse($block['is_ramadan']);

        $this->assertCount(7, $block['week']);
        $this->assertSame(['day' => 'sat', 'state' => 'open', 'slots' => [['09:00', '23:30']], 'is_today' => true], $block['week'][0]);
        $this->assertSame(['day' => 'sun', 'state' => '24h', 'is_today' => false], $block['week'][1]);
        $this->assertSame(['day' => 'mon', 'state' => 'closed', 'is_today' => false], $block['week'][2]);
        // يوم مش متعرّف بيتعامل كمقفول
        $this->assertSame(['day' => 'tue', 'state' => 'closed', 'is_today' => false], $block['week'][3]);
    }

    public function test_hours_resource_uses_ramadan_hours_when_in_ramadan(): void
    {
        app()->setLocale('ar');

        $place = new Place([
            'opening_hours' => self::daily('09:00', '23:30'),
            'ramadan_hours' => self::daily('20:00', '04:00'),
        ]);

        $request = Request::create('/');
        $request->attributes->set(PlaceCardResource::OPEN_NOW_ATTRIBUTE, new OpenNow(self::at(self::MON, '12:00'), true));

        $block = (new PlaceHoursResource($place))->resolve($request);

        $this->assertTrue($block['is_ramadan']);
        $this->assertFalse($block['is_open_now']);
        $this->assertSame('مقفول · بيفتح 8:00 م', $block['status_text']);
        $this->assertSame([['20:00', '04:00']], $block['week'][2]['slots']);
    }

    public function test_hours_resource_with_no_hours(): void
    {
        $block = (new PlaceHoursResource(new Place))->resolve(Request::create('/'));

        $this->assertSame(
            ['is_open_now' => null, 'state' => null, 'status_text' => null, 'is_ramadan' => false, 'week' => []],
            $block,
        );
    }
}
