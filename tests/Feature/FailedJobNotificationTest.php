<?php

namespace Tests\Feature;

use App\Listeners\NotifyAdminsOfFailedJob;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Exception;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Mockery;
use Tests\TestCase;

/**
 * الشغل اللي بيفشل في الخلفية (06 القسم 5): الأدمن بيشوفه في جرس اللوحة (مش صامت)، و`failed_jobs` بيتنضّف.
 */
class FailedJobNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Cache::flush();
    }

    private function failedEvent(string $class = 'App\Jobs\ProcessReviewPhoto', string $message = 'الأصل مش موجود'): JobFailed
    {
        $job = Mockery::mock(Job::class);
        $job->shouldReceive('payload')->andReturn(['displayName' => $class]);
        $job->shouldReceive('resolveName')->andReturn($class);
        $job->shouldReceive('attempts')->andReturn(2);

        return new JobFailed('database', $job, new Exception($message));
    }

    private function notificationsOf(User $user): int
    {
        return $user->notifications()->count();
    }

    public function test_active_admins_get_a_bell_with_the_job_and_the_error(): void
    {
        $admin = User::factory()->create()->assignRole('admin');

        app(NotifyAdminsOfFailedJob::class)->handle($this->failedEvent());

        $this->assertSame(1, $this->notificationsOf($admin));

        $data = $admin->notifications()->first()->data;
        $this->assertStringContainsString('ProcessReviewPhoto', $data['title']);
        $this->assertStringContainsString('الأصل مش موجود', $data['body']);
        $this->assertStringContainsString('2', $data['body']);
    }

    public function test_moderators_and_suspended_admins_are_left_out(): void
    {
        $moderator = User::factory()->create()->assignRole('moderator');
        $suspended = User::factory()->create(['status' => 'suspended', 'suspended_at' => now()])->assignRole('admin');
        $admin = User::factory()->create()->assignRole('admin');

        app(NotifyAdminsOfFailedJob::class)->handle($this->failedEvent());

        $this->assertSame(0, $this->notificationsOf($moderator));
        $this->assertSame(0, $this->notificationsOf($suspended));
        $this->assertSame(1, $this->notificationsOf($admin));
    }

    public function test_a_batch_of_the_same_failure_is_one_bell_and_another_job_is_another(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $listener = app(NotifyAdminsOfFailedJob::class);

        foreach (range(1, 5) as $ignored) {
            $listener->handle($this->failedEvent('App\Jobs\ProcessReviewPhoto'));
        }

        $this->assertSame(1, $this->notificationsOf($admin));

        $listener->handle($this->failedEvent('App\Jobs\RecalculatePlaceRating'));

        $this->assertSame(2, $this->notificationsOf($admin));
    }

    public function test_the_same_failure_rings_again_after_the_window(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $listener = app(NotifyAdminsOfFailedJob::class);

        $listener->handle($this->failedEvent());
        $this->travel(NotifyAdminsOfFailedJob::THROTTLE_MINUTES + 1)->minutes();
        $listener->handle($this->failedEvent());

        $this->assertSame(2, $this->notificationsOf($admin));
    }

    public function test_a_long_error_is_cut_and_only_its_first_line_is_shown(): void
    {
        $admin = User::factory()->create()->assignRole('admin');

        app(NotifyAdminsOfFailedJob::class)->handle(
            $this->failedEvent(message: str_repeat('خطأ طويل ', 60)."\nسطر تاني فيه تفاصيل داخلية"),
        );

        $body = $admin->notifications()->first()->data['body'];
        $this->assertStringNotContainsString('سطر تاني', $body);
        $this->assertLessThan(260, mb_strlen($body));
    }

    public function test_the_laravel_failed_job_event_reaches_the_listener(): void
    {
        $admin = User::factory()->create()->assignRole('admin');

        Event::dispatch($this->failedEvent('App\Jobs\RecordPlaceEvent'));

        $this->assertSame(1, $this->notificationsOf($admin));
    }

    public function test_failed_jobs_are_pruned_every_day(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($scheduled) => str_contains((string) $scheduled->command, 'queue:prune-failed'));

        $this->assertNotNull($event, 'queue:prune-failed is not in the schedule');
        $this->assertSame('20 4 * * *', $event->expression);
        $this->assertStringContainsString('--hours=168', (string) $event->command);
    }
}
