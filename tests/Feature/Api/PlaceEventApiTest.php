<?php

namespace Tests\Feature\Api;

use App\Jobs\RecordPlaceEvent;
use App\Models\Place;
use App\Models\PlaceEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * `POST /places/{slug}/events` (03 القسم 5.1 و5.5، ANL-02): بيقبل من غير token ومعاه `X-Device-Id`، 204 والكتابة
 * على الـ queue، و`view` مرة لكل جهاز في الساعة، وله limiter لوحده بره الحد العام.
 */
class PlaceEventApiTest extends TestCase
{
    use RefreshDatabase;

    private const DEVICE = '3f6c1c2e-8c1a-4b6f-9d55-2f5b8c0e7a11';

    private const OTHER_DEVICE = '9b1d2f4a-1c3e-4f6a-8b7c-0d9e8f7a6b5c';

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function place(string $status = Place::STATUS_ACTIVE, array $attributes = []): Place
    {
        return Place::factory()->status($status)->create($attributes);
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     */
    private function record(string $slug, array $body, ?string $device = self::DEVICE, array $headers = []): TestResponse
    {
        // كل طلب في الإنتاج process جديد؛ هنا الـ guard بيفتكر المستخدم اللي قبله
        $this->app['auth']->forgetGuards();

        if ($device !== null) {
            $headers['X-Device-Id'] = $device;
        }

        return $this->postJson("/api/v1/places/{$slug}/events", $body, ['Accept-Language' => 'ar'] + $headers);
    }

    public function test_a_guest_with_a_device_id_records_an_event_and_gets_204(): void
    {
        $place = $this->place();

        $this->record($place->slug, ['type' => 'directions'])->assertNoContent();

        $event = PlaceEvent::query()->sole();
        $this->assertSame($place->id, $event->place_id);
        $this->assertSame('directions', $event->type);
        $this->assertSame(self::DEVICE, $event->device_id);
        $this->assertNull($event->user_id);
    }

    public function test_every_mobile_type_is_accepted(): void
    {
        $place = $this->place();

        foreach (PlaceEvent::CLIENT_TYPES as $type) {
            $this->record($place->slug, ['type' => $type], self::OTHER_DEVICE)->assertNoContent();
        }

        $this->assertEqualsCanonicalizing(PlaceEvent::CLIENT_TYPES, PlaceEvent::query()->pluck('type')->all());
    }

    public function test_the_user_is_recorded_when_a_token_comes_with_the_request(): void
    {
        $place = $this->place();
        $user = User::factory()->create();
        $token = $user->createToken('mobile')->plainTextToken;

        $this->record($place->slug, ['type' => 'call'], headers: ['Authorization' => 'Bearer '.$token])->assertNoContent();

        $this->assertSame($user->id, PlaceEvent::query()->sole()->user_id);
    }

    public function test_a_stale_token_does_not_fail_the_event_and_it_is_recorded_as_a_guest(): void
    {
        $place = $this->place();

        $this->record($place->slug, ['type' => 'share'], headers: ['Authorization' => 'Bearer 999|expired'])->assertNoContent();

        $this->assertNull(PlaceEvent::query()->sole()->user_id);
    }

    public function test_the_device_id_is_required_and_must_be_a_uuid(): void
    {
        $place = $this->place();

        $this->record($place->slug, ['type' => 'view'], device: null)
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR')
            ->assertJsonValidationErrors(['device_id'], 'errors');

        $this->record($place->slug, ['type' => 'view'], device: 'not-a-uuid')
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR');

        $this->assertSame(0, PlaceEvent::query()->count());
    }

    public function test_the_device_id_is_stored_lowercase(): void
    {
        $place = $this->place();

        $this->record($place->slug, ['type' => 'view'], device: strtoupper(self::DEVICE))->assertNoContent();

        $this->assertSame(self::DEVICE, PlaceEvent::query()->sole()->device_id);
    }

    public function test_the_type_must_be_one_the_mobile_sends(): void
    {
        $place = $this->place();

        foreach ([[], ['type' => 'bogus'], ['type' => ''], ['type' => 'save'], ['type' => ['view']]] as $body) {
            $this->record($place->slug, $body)
                ->assertStatus(422)
                ->assertJsonPath('code', 'VALIDATION_ERROR')
                ->assertJsonValidationErrors(['type'], 'errors');
        }

        $this->assertSame(0, PlaceEvent::query()->count());
    }

    public function test_a_place_the_app_cannot_open_is_404_and_records_nothing(): void
    {
        $unavailable = [
            $this->place(Place::STATUS_DRAFT),
            $this->place(Place::STATUS_PENDING),
            $this->place(Place::STATUS_REJECTED),
            $this->place(Place::STATUS_HIDDEN),
        ];

        $deleted = $this->place();
        $deleted->delete();
        $unavailable[] = $deleted;

        foreach ($unavailable as $place) {
            $this->record($place->slug, ['type' => 'view'])
                ->assertNotFound()
                ->assertJsonPath('code', 'PLACE_NOT_AVAILABLE');
        }

        $this->record('no-such-place', ['type' => 'view'])->assertNotFound()->assertJsonPath('code', 'PLACE_NOT_AVAILABLE');

        $this->assertSame(0, PlaceEvent::query()->count());
    }

    public function test_a_permanently_closed_place_still_takes_events(): void
    {
        $place = $this->place(Place::STATUS_CLOSED);

        $this->record($place->slug, ['type' => 'view'])->assertNoContent();

        $this->assertSame(1, PlaceEvent::query()->where('place_id', $place->id)->count());
    }

    public function test_an_old_slug_records_for_the_same_place(): void
    {
        $place = $this->place();
        DB::table('place_slug_redirects')->insert(['place_id' => $place->id, 'old_slug' => 'old-name']);

        $this->record('old-name', ['type' => 'whatsapp'])->assertNoContent();

        $this->assertSame($place->id, PlaceEvent::query()->sole()->place_id);
    }

    public function test_the_request_only_queues_the_write(): void
    {
        Queue::fake();
        $place = $this->place();

        $this->record($place->slug, ['type' => 'view'])->assertNoContent();

        Queue::assertPushed(RecordPlaceEvent::class, fn (RecordPlaceEvent $job) => $job->placeId === $place->id
            && $job->type === 'view'
            && $job->deviceId === self::DEVICE);
        $this->assertSame(0, PlaceEvent::query()->count());
    }

    public function test_a_view_counts_once_per_device_per_place_per_hour(): void
    {
        $place = $this->place();
        $other = $this->place();
        Carbon::setTestNow('2026-12-06 10:00:00');

        $this->record($place->slug, ['type' => 'view'])->assertNoContent();

        // نفس الجهاز بعد دقيقة وبعد 59 دقيقة: نفس المشاهدة
        Carbon::setTestNow('2026-12-06 10:01:00');
        $this->record($place->slug, ['type' => 'view'])->assertNoContent();
        Carbon::setTestNow('2026-12-06 10:59:00');
        $this->record($place->slug, ['type' => 'view'])->assertNoContent();

        $this->assertSame(1, PlaceEvent::query()->where('type', 'view')->count());

        // جهاز تاني ومكان تاني بيتحسبوا لوحدهم
        $this->record($place->slug, ['type' => 'view'], self::OTHER_DEVICE)->assertNoContent();
        $this->record($other->slug, ['type' => 'view'])->assertNoContent();
        $this->assertSame(3, PlaceEvent::query()->where('type', 'view')->count());

        // بعد ساعة من أول مشاهدة بيتحسب من جديد
        Carbon::setTestNow('2026-12-06 11:01:00');
        $this->record($place->slug, ['type' => 'view'])->assertNoContent();
        $this->assertSame(4, PlaceEvent::query()->where('type', 'view')->count());
    }

    public function test_the_other_types_count_every_press(): void
    {
        $place = $this->place();

        $this->record($place->slug, ['type' => 'directions'])->assertNoContent();
        $this->record($place->slug, ['type' => 'directions'])->assertNoContent();
        $this->record($place->slug, ['type' => 'call'])->assertNoContent();

        $this->assertSame(2, PlaceEvent::query()->where('type', 'directions')->count());
        $this->assertSame(1, PlaceEvent::query()->where('type', 'call')->count());
    }

    public function test_the_event_keeps_the_time_of_the_request_not_of_the_worker(): void
    {
        Queue::fake();
        $place = $this->place();
        Carbon::setTestNow('2026-12-06 23:59:50');

        $this->record($place->slug, ['type' => 'call'])->assertNoContent();

        // الـ worker بيشغّل الـ job بعد منتصف الليل
        Carbon::setTestNow('2026-12-07 00:00:30');
        Queue::pushed(RecordPlaceEvent::class)->first()->handle();

        $this->assertSame('2026-12-06 23:59:50', PlaceEvent::query()->sole()->created_at->toDateTimeString());
    }

    public function test_a_place_deleted_for_good_before_the_job_runs_does_not_fail_it(): void
    {
        $job = new RecordPlaceEvent(placeId: 999_999, type: 'call', deviceId: self::DEVICE, userId: null, occurredAt: now()->getTimestamp());

        $job->handle();

        $this->assertSame(0, PlaceEvent::query()->count());
    }

    public function test_events_have_their_own_budget_outside_the_general_one(): void
    {
        $place = $this->place();
        config(['wain.rate_limit.guest' => 2, 'wain.rate_limit.events' => 4]);

        // أكتر من ميزانية التصفّح العامة (2) من غير ما حاجة تتحسب هناك
        for ($i = 0; $i < 4; $i++) {
            $this->record($place->slug, ['type' => 'directions'])->assertNoContent();
        }

        $this->record($place->slug, ['type' => 'directions'])
            ->assertStatus(429)
            ->assertJsonPath('code', 'TOO_MANY_REQUESTS');

        // التصفّح لسه معاه ميزانيته كاملة
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/categories', ['Accept-Language' => 'ar', 'X-Device-Id' => self::DEVICE])->assertOk();
        $this->getJson('/api/v1/categories', ['Accept-Language' => 'ar', 'X-Device-Id' => self::DEVICE])->assertOk();
        $this->getJson('/api/v1/categories', ['Accept-Language' => 'ar', 'X-Device-Id' => self::DEVICE])->assertStatus(429);
    }
}
