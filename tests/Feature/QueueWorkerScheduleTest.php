<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * الـ queue على الاستضافة المشتركة (06 القسم 4): مفيش worker شغّال دايماً، فالـ scheduler (cron كل دقيقة) هو اللي
 * بيشغّله. من غير السطر ده الصور ومتوسط التقييم والأحداث تفضل في جدول `jobs` على الاستضافة.
 */
class QueueWorkerScheduleTest extends TestCase
{
    public function test_the_queue_worker_is_started_every_minute_and_stops_before_the_next_one(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains((string) $event->command, 'queue:work'));

        $this->assertNotNull($event, 'queue:work is not in the schedule');
        $this->assertSame('* * * * *', $event->expression);
        $this->assertStringContainsString('--stop-when-empty', (string) $event->command);
        $this->assertStringContainsString('--max-time=55', (string) $event->command);
        $this->assertStringContainsString('--memory=', (string) $event->command);

        // its own process, so it never holds back the other commands due in the same minute
        $this->assertTrue($event->runInBackground);

        // a lock that outlives a killed worker by a day would stop the queue for that day
        $this->assertTrue($event->withoutOverlapping);
        $this->assertLessThanOrEqual(10, $event->expiresAt);
    }
}
