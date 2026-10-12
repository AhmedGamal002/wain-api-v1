<?php

namespace Tests\Feature;

use App\Support\PriceRange;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoPlaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class DemoPlaceSeederTest extends TestCase
{
    use RefreshDatabase;

    private const DAYS = ['sat', 'sun', 'mon', 'tue', 'wed', 'thu', 'fri'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoPlaceSeeder::class);
    }

    public function test_creates_thirty_places_with_the_intended_status_mix(): void
    {
        $this->assertSame(30, DB::table('places')->count());

        $byStatus = DB::table('places')->select('status', DB::raw('count(*) as c'))->groupBy('status')->pluck('c', 'status');

        $this->assertEquals(
            ['active' => 26, 'hidden' => 1, 'closed' => 1, 'draft' => 1, 'pending' => 1],
            $byStatus->all(),
        );
    }

    public function test_every_category_has_an_active_place(): void
    {
        $empty = DB::table('categories')->whereNotExists(
            fn ($q) => $q->select(DB::raw(1))->from('places')
                ->whereColumn('places.primary_category_id', 'categories.id')
                ->where('places.status', 'active'),
        )->count();

        $this->assertSame(0, $empty);
    }

    public function test_location_column_matches_latitude_and_longitude(): void
    {
        $mismatched = DB::selectOne(
            'SELECT COUNT(*) AS c FROM places WHERE ABS(ST_X(location) - longitude) > 0.000001 OR ABS(ST_Y(location) - latitude) > 0.000001',
        );

        $this->assertSame(0, (int) $mismatched->c);
    }

    public function test_every_place_except_the_draft_meets_the_nine_publish_conditions(): void
    {
        $checked = 0;

        foreach (DB::table('places')->where('status', '!=', 'draft')->get() as $place) {
            $this->assertSame([], $this->missingPublishConditions($place), "{$place->slug} ناقص شروط نشر");
            $checked++;
        }

        $this->assertSame(29, $checked);
    }

    public function test_the_draft_is_deliberately_incomplete(): void
    {
        $draft = DB::table('places')->where('status', 'draft')->first();

        $this->assertSame([5, 6, 9], $this->missingPublishConditions($draft));
    }

    public function test_price_range_matches_the_rule_for_every_place(): void
    {
        foreach (DB::table('places')->get() as $place) {
            $this->assertSame(PriceRange::forMaxPrice($place->price_max), $place->price_range, $place->slug);
        }

        $ranges = DB::table('places')->distinct()->pluck('price_range')->all();
        sort($ranges);
        $this->assertSame(['budget', 'mid', 'premium'], $ranges);
    }

    public function test_tiers_are_consistent_and_only_on_published_active_places(): void
    {
        $this->assertEquals(2, DB::table('places')->where('listing_tier', 'premium')->count());
        $this->assertEquals(4, DB::table('places')->where('listing_tier', 'featured')->count());

        foreach (DB::table('places')->get() as $place) {
            $this->assertSame(
                ['premium' => 1, 'featured' => 2, 'free' => 3][$place->listing_tier],
                (int) $place->tier_rank,
                $place->slug,
            );

            if ($place->listing_tier !== 'free') {
                $this->assertSame('active', $place->status, "{$place->slug}: باقة مدفوعة على مكان مش منشور");
                $this->assertNotNull($place->tier_starts_at);
                $this->assertNotNull($place->tier_ends_at);
            }
        }

        // واحدة بتنتهي خلال 7 أيام (تنبيه الأدمن في A1 4.9)
        $expiring = DB::table('places')->where('listing_tier', '!=', 'free')->where('tier_ends_at', '<', now()->addDays(7))->count();
        $this->assertSame(1, $expiring);
    }

    public function test_opening_hours_cover_the_cases_open_now_must_handle(): void
    {
        $states = [];
        $crossesMidnight = false;
        $twoSlots = false;

        foreach (DB::table('places')->whereNotNull('opening_hours')->get() as $place) {
            foreach (json_decode($place->opening_hours, true) as $day) {
                $states[$day['state']] = true;
                foreach ($day['slots'] ?? [] as [$from, $to]) {
                    $crossesMidnight = $crossesMidnight || $to < $from;
                }
                $twoSlots = $twoSlots || count($day['slots'] ?? []) === 2;
            }
        }

        $this->assertEqualsCanonicalizing(['open', 'closed', '24h'], array_keys($states));
        $this->assertTrue($crossesMidnight, 'محتاج مكان بيقفل بعد نص الليل');
        $this->assertTrue($twoSlots, 'محتاج يوم بفترتين');
        $this->assertSame(3, DB::table('places')->whereNotNull('ramadan_hours')->count());
    }

    public function test_ratings_and_verification_dates_cover_the_edge_cases(): void
    {
        $this->assertGreaterThan(0, DB::table('places')->where('rating_count', 0)->count(), 'مكان من غير تقييمات');
        $this->assertGreaterThan(0, DB::table('places')->where('rating_count', '>', 0)->where('rating_count', '<', 3)->count(), 'أقل من 3 = «جديد»');
        $this->assertGreaterThanOrEqual(2, DB::table('places')->where('verified_at', '<', now()->subDays(90))->count(), 'ما اتأكدتش من 90 يوم');
        $this->assertGreaterThanOrEqual(1, DB::table('places')->where('verified_at', '>', now()->subDays(7))->count());
    }

    public function test_photos_are_ready_and_cover_belongs_to_the_same_place(): void
    {
        $this->assertSame(0, DB::table('place_photos')->where('status', '!=', 'ready')->count());

        $foreign = DB::table('places')
            ->join('place_photos', 'place_photos.id', '=', 'places.cover_photo_id')
            ->whereColumn('place_photos.place_id', '!=', 'places.id')
            ->count();
        $this->assertSame(0, $foreign);

        $restaurants = DB::table('places')->join('categories', 'categories.id', '=', 'places.primary_category_id')
            ->where('categories.slug', 'restaurants')->pluck('places.id');
        foreach ($restaurants as $id) {
            $this->assertSame(2, DB::table('place_photos')->where('place_id', $id)->where('type', 'menu')->count());
        }

        $variants = json_decode(DB::table('place_photos')->value('variants'), true);
        $this->assertEqualsCanonicalizing(['thumb', 'medium', 'large'], array_keys($variants));
        $this->assertStringStartsWith('https://', $variants['thumb']);
    }

    public function test_contact_fields_use_the_documented_formats(): void
    {
        foreach (DB::table('places')->whereNotNull('phone')->get() as $place) {
            $this->assertMatchesRegularExpression('/^\+20\d{10}$/', $place->phone, $place->slug);
        }

        foreach (DB::table('places')->whereNotNull('instagram')->get() as $place) {
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9._]{1,30}$/', $place->instagram, $place->slug);
        }
    }

    public function test_demo_user_cannot_sign_in_or_open_the_panel(): void
    {
        $user = DB::table('users')->where('email', DemoPlaceSeeder::DEMO_USER_EMAIL)->first();

        $this->assertNull($user->password);
        $this->assertSame(0, DB::table('model_has_roles')->where('model_id', $user->id)->count());
    }

    public function test_rerun_is_idempotent(): void
    {
        $before = [
            DB::table('places')->count(),
            DB::table('place_photos')->count(),
            DB::table('place_category')->count(),
            DB::table('attribute_place')->count(),
            DB::table('users')->count(),
        ];

        $this->seed(DemoPlaceSeeder::class);

        $after = [
            DB::table('places')->count(),
            DB::table('place_photos')->count(),
            DB::table('place_category')->count(),
            DB::table('attribute_place')->count(),
            DB::table('users')->count(),
        ];

        $this->assertSame($before, $after);
    }

    public function test_refuses_to_run_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->expectException(RuntimeException::class);

        // مباشرةً مش `db:seed` (الأمر نفسه بيطلب تأكيد في production قبل ما الـ seeder يتنفّذ)
        $this->app->make(DemoPlaceSeeder::class)->run();
    }

    public function test_database_seeder_adds_demo_places_in_local_only(): void
    {
        DB::table('places')->delete();

        $this->seed(DatabaseSeeder::class);
        $this->assertSame(0, DB::table('places')->count(), 'testing env: من غير أماكن تجريبية');

        $this->app['env'] = 'local';
        $this->seed(DatabaseSeeder::class);
        $this->assertSame(30, DB::table('places')->count());
    }

    /**
     * شروط النشر التسعة (03 القسم 4.3). بترجّع أرقام البنود الناقصة.
     *
     * @return list<int>
     */
    private function missingPublishConditions(object $place): array
    {
        $missing = [];

        if (blank($place->name_ar) || blank($place->name_en)) {
            $missing[] = 1;
        }

        if (! DB::table('place_category')->where('place_id', $place->id)->where('category_id', $place->primary_category_id)->exists()) {
            $missing[] = 2;
        }

        $insideEgypt = $place->latitude >= 22 && $place->latitude <= 31.7 && $place->longitude >= 24.7 && $place->longitude <= 37;
        if ($place->area_id === null || ! $insideEgypt) {
            $missing[] = 3;
        }

        if (blank($place->address_ar)) {
            $missing[] = 4;
        }

        $length = mb_strlen((string) $place->description_ar);
        if ($length < 50 || $length > 1000) {
            $missing[] = 5;
        }

        if (DB::table('attribute_place')->where('place_id', $place->id)->count() < 3) {
            $missing[] = 6;
        }

        if ($place->price_min === null || $place->price_max === null || $place->price_max < $place->price_min) {
            $missing[] = 7;
        }

        if (! $this->hoursAreComplete($place->opening_hours)) {
            $missing[] = 8;
        }

        $coverReady = $place->cover_photo_id !== null && DB::table('place_photos')
            ->where('id', $place->cover_photo_id)->where('place_id', $place->id)->where('status', 'ready')->exists();
        if (! $coverReady) {
            $missing[] = 9;
        }

        return $missing;
    }

    private function hoursAreComplete(?string $json): bool
    {
        if ($json === null) {
            return false;
        }

        $hours = json_decode($json, true);

        foreach (self::DAYS as $day) {
            $state = $hours[$day]['state'] ?? null;

            if (! in_array($state, ['open', 'closed', '24h'], true)) {
                return false;
            }

            if ($state === 'open' && ! $this->slotsDoNotOverlap($hours[$day]['slots'] ?? [])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<array{0: string, 1: string}>  $slots
     */
    private function slotsDoNotOverlap(array $slots): bool
    {
        if ($slots === []) {
            return false;
        }

        $minutes = fn (string $time) => (int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2);

        $ranges = array_map(function (array $slot) use ($minutes) {
            [$from, $to] = [$minutes($slot[0]), $minutes($slot[1])];

            return [$from, $to <= $from ? $to + 1440 : $to];
        }, $slots);

        usort($ranges, fn ($a, $b) => $a[0] <=> $b[0]);

        for ($i = 1; $i < count($ranges); $i++) {
            if ($ranges[$i][0] < $ranges[$i - 1][1]) {
                return false;
            }
        }

        return true;
    }
}
