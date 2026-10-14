<?php

namespace Tests\Feature\Api;

use App\Models\Place;
use App\Models\SearchLog;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * سجل البحث (02 F4 «تسجيل البحث»، 06 القسم 5): `log=1` بس هو اللي بيكتب صف، والكلمة مطبَّعة، وعدد النتايج هو
 * اللي المستخدم شافه، وبيتمسح بعد 90 يوم.
 */
class SearchLogApiTest extends TestCase
{
    use RefreshDatabase;

    private const AR = ['Accept-Language' => 'ar'];

    private function place(string $nameAr, array $attributes = []): Place
    {
        return Place::factory()->status(Place::STATUS_ACTIVE)->create([
            'name_ar' => $nameAr,
            'name_en' => 'Log Venue '.Str::random(6),
            ...$attributes,
        ]);
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array<string, string>  $headers
     */
    private function search(string $q, array $params = [], array $headers = []): TestResponse
    {
        return $this->getJson('/api/v1/search?'.http_build_query(['q' => $q, ...$params]), self::AR + $headers);
    }

    public function test_a_search_is_not_logged_without_the_flag(): void
    {
        $this->place('مطعم السلطان');

        $this->search('سلطان')->assertOk();
        $this->search('سلطان', ['log' => 0])->assertOk();
        $this->search('سلطان', ['log' => '0'])->assertOk();

        $this->assertSame(0, SearchLog::query()->count());
    }

    public function test_log_one_writes_a_row_with_the_normalized_query_and_the_count_the_user_saw(): void
    {
        $this->place('مطعم السلطان');
        $this->place('مكان تاني', ['description_ar' => 'اكلات السلطان']);

        $this->search('الـسُلطان', ['log' => 1])->assertOk()->assertJsonPath('meta.total', 2);

        $log = SearchLog::query()->sole();

        // التطويل والتشكيل اتشالوا، و«ال» لسه في الكلمة المسجّلة (بتتشال بس وقت البحث)
        $this->assertSame('السلطان', $log->query);
        $this->assertSame(2, $log->results_count);
        $this->assertNull($log->area_id);
        $this->assertNull($log->user_id);
        $this->assertNull($log->device_id);
        $this->assertNotNull($log->created_at);
    }

    public function test_the_query_is_stored_normalized(): void
    {
        $this->search('  قَهْوة   المحطة  ', ['log' => 1])->assertOk();

        $this->assertSame('قهوه المحطه', SearchLog::query()->sole()->query);
    }

    public function test_a_search_without_results_is_logged_with_zero(): void
    {
        $this->search('كلمةمالهاش', ['log' => 1])->assertOk()->assertJsonPath('meta.total', 0);

        $log = SearchLog::query()->sole();

        $this->assertSame(0, $log->results_count);
        $this->assertSame('كلمهمالهاش', $log->query);
    }

    public function test_the_count_is_the_one_after_a_spelling_correction(): void
    {
        $this->place('مطعم الطازج');

        $this->search('الطازح', ['log' => 1])
            ->assertOk()
            ->assertJsonPath('meta.corrected_query', 'طازج')
            ->assertJsonPath('meta.total', 1);

        $log = SearchLog::query()->sole();

        // اللي اتكتب (مطبَّع) مش اللي اتصحّح
        $this->assertSame('الطازح', $log->query);
        $this->assertSame(1, $log->results_count);
    }

    public function test_the_area_the_user_and_the_device_are_recorded(): void
    {
        $place = $this->place('مطعم السلطان');
        $user = new User(['name' => 'مستخدم', 'email' => 'searcher@example.com', 'password' => 'secret123']);
        $user->save();
        $deviceId = '3F2504E0-4F89-41D3-9A0C-0305E82C3301';

        $this->search('سلطان', ['log' => 1, 'area' => $place->area->slug], [
            'Authorization' => 'Bearer '.$user->createToken('mobile')->plainTextToken,
            'X-Device-Id' => $deviceId,
        ])->assertOk();

        $log = SearchLog::query()->sole();

        $this->assertSame($place->area_id, $log->area_id);
        $this->assertSame($user->id, $log->user_id);
        $this->assertSame(strtolower($deviceId), $log->device_id);
    }

    public function test_a_device_id_that_is_not_a_uuid_is_not_stored(): void
    {
        $this->search('سلطان', ['log' => 1], ['X-Device-Id' => 'not-a-uuid'])->assertOk();

        $this->assertNull(SearchLog::query()->sole()->device_id);
    }

    public function test_symbols_only_is_not_logged(): void
    {
        $this->search('؟؟', ['log' => 1])->assertOk()->assertJsonPath('meta.total', 0);

        $this->assertSame(0, SearchLog::query()->count());
    }

    public function test_the_log_flag_is_validated(): void
    {
        $this->search('سلطان', ['log' => 'banana'])->assertStatus(422)->assertJsonValidationErrors('log');
    }

    public function test_the_response_is_the_same_with_and_without_logging(): void
    {
        $this->place('مطعم السلطان');

        $plain = $this->search('سلطان')->assertOk()->json();
        $logged = $this->search('سلطان', ['log' => 1])->assertOk()->json();

        $this->assertSame($plain, $logged);
    }

    public function test_the_prune_command_keeps_ninety_days(): void
    {
        SearchLog::query()->create(['query' => 'قديم', 'results_count' => 0, 'created_at' => now()->subDays(91)]);
        SearchLog::query()->create(['query' => 'على الحد', 'results_count' => 3, 'created_at' => now()->subDays(89)]);
        SearchLog::query()->create(['query' => 'جديد', 'results_count' => 5]);

        $this->artisan('search:prune-logs')->assertSuccessful();

        $this->assertEqualsCanonicalizing(['على الحد', 'جديد'], SearchLog::query()->pluck('query')->all());

        $this->artisan('search:prune-logs', ['--days' => 30])->assertSuccessful();

        $this->assertSame(['جديد'], SearchLog::query()->pluck('query')->all());
    }

    public function test_the_prune_is_scheduled(): void
    {
        $commands = collect(app(Schedule::class)->events())->pluck('command')->implode(' ');

        $this->assertStringContainsString('search:prune-logs', $commands);
    }
}
