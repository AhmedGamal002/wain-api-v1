<?php

namespace Tests\Feature\Api;

use App\Events\PlaceChanged;
use App\Models\Place;
use App\Support\PlaceHoursBuilder;
use Database\Seeders\DemoPlaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * «مفتوح دلوقتي» (03 القسم 7.1) على الـ API: is_open_now في الكارت وفلتر open_now، بتوقيت القاهرة
 * مع التوقيت الصيفي ومواعيد رمضان والفترات اللي بتعدّي نص الليل.
 */
class OpenNowApiTest extends TestCase
{
    use RefreshDatabase;

    private const SLUG = 'el-mahatta-coffee';

    private const AR = ['Accept-Language' => 'ar'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoPlaceSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $hours
     */
    private function setHours(array $hours, ?array $ramadan = null, string $slug = self::SLUG): void
    {
        DB::table('places')->where('slug', $slug)->update([
            'opening_hours' => json_encode($hours),
            'ramadan_hours' => $ramadan === null ? null : json_encode($ramadan),
        ]);

        PlaceHoursBuilder::rebuild((int) DB::table('places')->where('slug', $slug)->value('id'));
    }

    /**
     * @return array<string, mixed>
     */
    private function daily(string $from, string $to): array
    {
        return array_fill_keys(['sat', 'sun', 'mon', 'tue', 'wed', 'thu', 'fri'], ['state' => 'open', 'slots' => [[$from, $to]]]);
    }

    private function at(string $utc): void
    {
        Carbon::setTestNow(Carbon::parse($utc, 'UTC'));
    }

    private function cardOpen(string $slug = self::SLUG): ?bool
    {
        $cards = $this->getJson('/api/v1/places?per_page=50', self::AR)->assertOk()->json('data');
        $card = collect($cards)->firstWhere('slug', $slug);
        $this->assertNotNull($card, "{$slug} should be listed");

        return $card['is_open_now'];
    }

    private function inOpenNowFilter(string $slug = self::SLUG): bool
    {
        $cards = $this->getJson('/api/v1/places?per_page=50&open_now=1', self::AR)->assertOk()->json('data');

        return in_array($slug, array_column($cards, 'slug'), true);
    }

    public function test_card_and_filter_agree_inside_and_outside_hours(): void
    {
        $this->setHours($this->daily('09:00', '23:30'));

        // 2026-10-12 هو الاتنين. 10:00 UTC = 13:00 القاهرة (+3 صيفي)
        $this->at('2026-10-12 10:00:00');
        $this->assertTrue($this->cardOpen());
        $this->assertTrue($this->inOpenNowFilter());

        // 22:00 UTC = 01:00 القاهرة تاني يوم، مقفول
        $this->at('2026-10-12 22:00:00');
        $this->assertFalse($this->cardOpen());
        $this->assertFalse($this->inOpenNowFilter());
    }

    public function test_place_without_hours_is_null_and_never_in_the_filter(): void
    {
        DB::table('places')->where('slug', self::SLUG)->update(['opening_hours' => null, 'ramadan_hours' => null]);
        PlaceHoursBuilder::rebuild((int) DB::table('places')->where('slug', self::SLUG)->value('id'));

        $this->at('2026-10-12 10:00:00');

        $this->assertNull($this->cardOpen());
        $this->assertFalse($this->inOpenNowFilter());
    }

    public function test_hours_that_cross_midnight_use_yesterdays_slot(): void
    {
        // مفتوح من 18:00 لـ 02:00 كل يوم
        $this->setHours($this->daily('18:00', '02:00'));

        // 22:30 UTC الاتنين = 01:30 القاهرة الثلاثاء: لسه من فترة الاتنين
        $this->at('2026-10-12 22:30:00');
        $this->assertTrue($this->cardOpen());
        $this->assertTrue($this->inOpenNowFilter());

        // 23:30 UTC الاتنين = 02:30 القاهرة: خلصت
        $this->at('2026-10-12 23:30:00');
        $this->assertFalse($this->cardOpen());
        $this->assertFalse($this->inOpenNowFilter());
    }

    public function test_a_24_hour_place_is_open_around_the_clock_in_the_card_and_the_filter(): void
    {
        $this->setHours(array_fill_keys(['sat', 'sun', 'mon', 'tue', 'wed', 'thu', 'fri'], ['state' => '24h']));

        // 2026-10-12 هو الاتنين (+3 صيفي): نص الليل، الضهر، وآخر دقيقة في الجمعة وأول دقيقة في السبت (بداية الأسبوع)
        foreach (['2026-10-12 00:00:00', '2026-10-12 09:00:00', '2026-10-16 20:59:00', '2026-10-16 21:01:00'] as $utc) {
            $this->at($utc);

            $this->assertTrue($this->cardOpen(), $utc);
            $this->assertTrue($this->inOpenNowFilter(), $utc);
        }
    }

    public function test_cairo_time_decides_the_weekday_not_utc(): void
    {
        // الجمعة 13:00 لـ 01:00 والسبت مقفول
        $hours = array_fill_keys(['sat', 'sun', 'mon', 'tue', 'wed', 'thu'], ['state' => 'closed']);
        $hours['fri'] = ['state' => 'open', 'slots' => [['13:00', '01:00']]];
        $this->setHours($hours);

        // 2026-10-09 جمعة. 21:30 UTC = 00:30 القاهرة السبت: لسه من فترة الجمعة
        $this->at('2026-10-09 21:30:00');
        $this->assertTrue($this->cardOpen());

        // 23:30 UTC الجمعة = 02:30 القاهرة السبت: مقفول (بالـ UTC كان لسه الجمعة المفتوحة)
        $this->at('2026-10-09 23:30:00');
        $this->assertFalse($this->cardOpen());
    }

    public function test_daylight_saving_ends_at_midnight_on_friday_30_october_2026(): void
    {
        $this->setHours($this->daily('09:00', '23:30'));

        // قبل نهاية الصيفي (+3): 20:29 UTC = 23:29 مفتوح، 20:31 UTC = 23:31 مقفول
        $this->at('2026-10-29 20:29:00');
        $this->assertTrue($this->cardOpen());
        $this->at('2026-10-29 20:31:00');
        $this->assertFalse($this->cardOpen());

        // بعد نهايته (+2): 06:30 UTC = 08:30 مقفول (بفرق +3 الثابت كان هيبان 09:30 مفتوح)
        $this->at('2026-10-30 06:30:00');
        $this->assertFalse($this->cardOpen());
        $this->assertFalse($this->inOpenNowFilter());

        // 07:00 UTC = 09:00 مفتوح
        $this->at('2026-10-30 07:00:00');
        $this->assertTrue($this->cardOpen());
        $this->assertTrue($this->inOpenNowFilter());
    }

    public function test_daylight_saving_starts_on_friday_24_april_2026(): void
    {
        $this->setHours($this->daily('09:00', '23:30'));

        // بعد بداية الصيفي (+3): 06:30 UTC = 09:30 مفتوح (بفرق +2 كان هيبان 08:30 مقفول)
        $this->at('2026-04-24 06:30:00');
        $this->assertTrue($this->cardOpen());
        $this->assertTrue($this->inOpenNowFilter());

        $this->at('2026-04-24 05:30:00');
        $this->assertFalse($this->cardOpen());
    }

    public function test_ramadan_hours_replace_normal_hours_only_inside_ramadan(): void
    {
        DB::table('settings')->insert([
            ['key' => 'ramadan_starts_at', 'value' => '2027-02-08'],
            ['key' => 'ramadan_ends_at', 'value' => '2027-03-09'],
        ]);
        // عادي 09:00 لـ 23:30، ورمضان من الفطار للسحور 20:00 لـ 04:00
        $this->setHours($this->daily('09:00', '23:30'), $this->daily('20:00', '04:00'));

        // الظهر في رمضان (2027-02-20، الفرق +2): مقفول بمواعيد رمضان
        $this->at('2027-02-20 10:00:00');
        $this->assertFalse($this->cardOpen());
        $this->assertFalse($this->inOpenNowFilter());

        // بعد الفطار بنفس اليوم: مفتوح
        $this->at('2027-02-20 19:00:00');
        $this->assertTrue($this->cardOpen());
        $this->assertTrue($this->inOpenNowFilter());

        // بعد رمضان: المواعيد العادية، الظهر مفتوح
        $this->at('2027-03-20 10:00:00');
        $this->assertTrue($this->cardOpen());
    }

    public function test_place_without_ramadan_hours_keeps_normal_hours_during_ramadan(): void
    {
        DB::table('settings')->insert([
            ['key' => 'ramadan_starts_at', 'value' => '2027-02-08'],
            ['key' => 'ramadan_ends_at', 'value' => '2027-03-09'],
        ]);
        $this->setHours($this->daily('09:00', '23:30'));

        $this->at('2027-02-20 10:00:00');
        $this->assertTrue($this->cardOpen());
        $this->assertTrue($this->inOpenNowFilter());
    }

    public function test_open_now_must_be_boolean(): void
    {
        $this->getJson('/api/v1/places?open_now=maybe', self::AR)->assertStatus(422)->assertJsonValidationErrors('open_now');
    }

    public function test_place_hours_is_rebuilt_by_the_place_changed_event(): void
    {
        $place = Place::query()->where('slug', self::SLUG)->firstOrFail();
        $id = $place->id;

        DB::table('places')->where('id', $id)->update([
            'opening_hours' => json_encode(['mon' => ['state' => 'open', 'slots' => [['10:00', '12:00']]]]),
            'ramadan_hours' => json_encode(['mon' => ['state' => '24h']]),
        ]);

        PlaceChanged::dispatch($place->fresh());

        $rows = DB::table('place_hours')->where('place_id', $id)->orderBy('is_ramadan')->get(['is_ramadan', 'opens_minute', 'closes_minute']);
        $this->assertCount(2, $rows);
        // الاتنين = يوم رقم 2 بعد السبت: 2*1440 = 2880
        $this->assertEquals([0, 2880 + 600, 2880 + 720], [(int) $rows[0]->is_ramadan, $rows[0]->opens_minute, $rows[0]->closes_minute]);
        $this->assertEquals([1, 2880, 2880 + 1440], [(int) $rows[1]->is_ramadan, $rows[1]->opens_minute, $rows[1]->closes_minute]);
    }

    public function test_rebuild_command_fills_every_place_and_is_idempotent(): void
    {
        DB::table('place_hours')->truncate();

        $this->artisan('places:rebuild-hours')->assertSuccessful();
        $first = DB::table('place_hours')->count();
        $this->assertGreaterThan(0, $first);

        $this->artisan('places:rebuild-hours')->assertSuccessful();
        $this->assertSame($first, DB::table('place_hours')->count());

        $placesWithHours = DB::table('places')->whereNotNull('opening_hours')->count();
        $this->assertSame($placesWithHours, DB::table('place_hours')->distinct()->count('place_id'));
    }
}
