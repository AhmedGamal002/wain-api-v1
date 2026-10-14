<?php

namespace Tests\Unit;

use App\Support\PlaceActivityPresenter;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * نوع التغيير وسطوره في سجل المكان (03 القسم 4.7): من غير داتابيز، بس شكل `properties` بتاع spatie.
 */
class PlaceActivityPresenterTest extends TestCase
{
    /** @param  array<string, mixed>  $properties */
    private function activity(string $event, array $properties = [], ?string $description = null): Activity
    {
        $activity = new Activity;
        $activity->event = $event;
        $activity->description = $description ?? $event;
        $activity->properties = collect($properties);

        return $activity;
    }

    public function test_the_type_comes_from_the_event_and_the_changed_fields(): void
    {
        $this->assertSame('إنشاء', PlaceActivityPresenter::type($this->activity('created', ['attributes' => ['name_ar' => 'س']])));
        $this->assertSame('صور', PlaceActivityPresenter::type($this->activity('photos')));
        $this->assertSame('حذف', PlaceActivityPresenter::type($this->activity('deleted')));
        $this->assertSame('استرجاع', PlaceActivityPresenter::type($this->activity('restored')));

        $this->assertSame('تغيير حالة', PlaceActivityPresenter::type($this->activity('updated', [
            'attributes' => ['status' => 'hidden', 'status_reason' => 'شكوى'],
        ])));
        $this->assertSame('تغيير باقة', PlaceActivityPresenter::type($this->activity('updated', [
            'attributes' => ['listing_tier' => 'premium', 'tier_ends_at' => '2026-12-31 21:59:59'],
        ])));
        $this->assertSame('تعديل', PlaceActivityPresenter::type($this->activity('updated', [
            'attributes' => ['phone' => '+201001234567'],
        ])));
    }

    public function test_lines_show_old_and_new_values_with_readable_labels(): void
    {
        $lines = PlaceActivityPresenter::lines($this->activity('updated', [
            'attributes' => ['status' => 'hidden', 'status_reason' => 'شكوى', 'listing_tier' => 'premium'],
            'old' => ['status' => 'active', 'status_reason' => null, 'listing_tier' => 'free'],
        ]));

        $this->assertSame([
            'الحالة: منشور ← مخفي',
            'السبب: فاضي ← شكوى',
            'الباقة: مجاني ← بريميوم',
        ], $lines);
    }

    public function test_dates_are_shown_in_cairo_time_and_long_fields_are_summarised(): void
    {
        $lines = PlaceActivityPresenter::lines($this->activity('updated', [
            'attributes' => [
                'tier_ends_at' => '2026-12-31 21:59:59',
                'closed_at' => '2026-11-22',
                'description_ar' => 'وصف طويل جداً',
                'opening_hours' => ['sat' => ['state' => 'closed']],
            ],
            'old' => ['tier_ends_at' => null, 'closed_at' => null, 'description_ar' => 'قديم', 'opening_hours' => null],
        ]));

        $this->assertSame([
            'نهاية الباقة: فاضي ← 2026-12-31 23:59',
            'تاريخ القفل: فاضي ← 2026-11-22',
            'الوصف بالعربي: اتغيّر',
            'المواعيد: اتغيّر',
        ], $lines);
    }

    public function test_a_note_and_a_description_only_log_are_shown(): void
    {
        $this->assertSame(['ملاحظة: INV-7'], PlaceActivityPresenter::lines($this->activity('updated', ['note' => 'INV-7'])));
        $this->assertSame(['رفع صورة المعرض'], PlaceActivityPresenter::lines($this->activity('photos', [], 'رفع صورة المعرض')));
    }
}
