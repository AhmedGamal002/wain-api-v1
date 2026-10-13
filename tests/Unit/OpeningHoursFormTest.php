<?php

namespace Tests\Unit;

use App\Support\OpeningHours;
use App\Support\OpeningHoursForm;
use App\Support\PlacePublishCheck;
use PHPUnit\Framework\TestCase;

/**
 * الجسر بين `opening_hours` المخزَّن وحقول تاب المواعيد (03 القسم 4.4).
 */
class OpeningHoursFormTest extends TestCase
{
    public function test_time_options_step_by_fifteen_minutes_in_twelve_hour_labels(): void
    {
        $options = OpeningHoursForm::timeOptions();

        $this->assertCount(96, $options);
        $this->assertSame('12:00 ص', $options['00:00']);
        $this->assertSame('9:30 ص', $options['09:30']);
        $this->assertSame('12:15 م', $options['12:15']);
        $this->assertSame('11:45 م', $options['23:45']);
        $this->assertArrayNotHasKey('24:00', $options);
    }

    public function test_stored_hours_round_trip_through_the_form_state(): void
    {
        $stored = [
            'sat' => ['state' => 'open', 'slots' => [['09:00', '23:30']]],
            'sun' => ['state' => 'open', 'slots' => [['09:00', '14:00'], ['18:00', '02:00']]],
            'mon' => ['state' => 'closed'],
            'tue' => ['state' => '24h'],
            'wed' => ['state' => 'open', 'slots' => [['09:00', '23:30']]],
            'thu' => ['state' => 'open', 'slots' => [['09:00', '01:00']]],
            'fri' => ['state' => 'open', 'slots' => [['13:00', '01:00']]],
        ];

        $form = OpeningHoursForm::toFormState($stored);

        $this->assertSame('open', $form['sun']['state']);
        $this->assertSame('09:00', $form['sun']['slot1_from']);
        $this->assertSame('18:00', $form['sun']['slot2_from']);
        $this->assertTrue($form['sun']['has_second']);
        $this->assertFalse($form['sat']['has_second']);
        $this->assertSame('closed', $form['mon']['state']);

        $this->assertSame($stored, OpeningHoursForm::fromFormState($form));
    }

    public function test_empty_or_missing_hours_give_a_blank_form_and_null_back(): void
    {
        $form = OpeningHoursForm::toFormState(null);

        $this->assertSame(OpeningHours::DAYS, array_keys($form));
        $this->assertNull($form['sat']['state']);
        $this->assertNull(OpeningHoursForm::fromFormState($form));
        $this->assertNull(OpeningHoursForm::fromFormState(null));
    }

    public function test_incomplete_slot_is_dropped_so_the_hours_condition_is_not_met(): void
    {
        $form = OpeningHoursForm::toFormState(null);
        foreach (OpeningHours::DAYS as $day) {
            $form[$day]['state'] = 'open';
            $form[$day]['slot1_from'] = '09:00';
            $form[$day]['slot1_to'] = '23:00';
        }
        $form['sat']['slot1_to'] = null;

        $hours = OpeningHoursForm::fromFormState($form);

        $this->assertSame([], $hours['sat']['slots']);
        $this->assertFalse(PlacePublishCheck::hoursComplete($hours));

        $form['sat']['slot1_to'] = '23:00';
        $this->assertTrue(PlacePublishCheck::hoursComplete(OpeningHoursForm::fromFormState($form)));
    }

    public function test_second_slot_is_ignored_unless_toggled_on(): void
    {
        $form = OpeningHoursForm::toFormState(null);
        $form['sat'] = [
            'state' => 'open',
            'slot1_from' => '09:00', 'slot1_to' => '14:00',
            'has_second' => false,
            'slot2_from' => '18:00', 'slot2_to' => '23:00',
        ];

        $this->assertSame([['09:00', '14:00']], OpeningHoursForm::fromFormState($form)['sat']['slots']);

        $form['sat']['has_second'] = true;
        $this->assertSame([['09:00', '14:00'], ['18:00', '23:00']], OpeningHoursForm::fromFormState($form)['sat']['slots']);
    }

    public function test_closed_and_24h_days_drop_any_slot_values(): void
    {
        $form = OpeningHoursForm::toFormState(null);
        $form['sat'] = ['state' => 'closed', 'slot1_from' => '09:00', 'slot1_to' => '14:00', 'has_second' => false, 'slot2_from' => null, 'slot2_to' => null];
        $form['sun'] = ['state' => '24h', 'slot1_from' => '09:00', 'slot1_to' => '14:00', 'has_second' => false, 'slot2_from' => null, 'slot2_to' => null];

        $this->assertSame(
            ['sat' => ['state' => 'closed'], 'sun' => ['state' => '24h']],
            OpeningHoursForm::fromFormState($form),
        );
    }

    public function test_day_error_names_the_day_and_the_problem(): void
    {
        $day = fn (array $over) => array_merge([
            'state' => 'open', 'slot1_from' => '09:00', 'slot1_to' => '14:00',
            'has_second' => false, 'slot2_from' => null, 'slot2_to' => null,
        ], $over);

        $this->assertNull(OpeningHoursForm::dayError('sat', $day([])));
        $this->assertNull(OpeningHoursForm::dayError('sat', $day(['state' => 'closed', 'slot1_to' => null])));
        // بعد نص الليل مسموح (18:00 لـ 02:00)
        $this->assertNull(OpeningHoursForm::dayError('sat', $day(['slot1_from' => '18:00', 'slot1_to' => '02:00'])));

        $this->assertSame('السبت: بداية الفترة زي نهايتها', OpeningHoursForm::dayError('sat', $day(['slot1_to' => '09:00'])));
        $this->assertSame('الأحد: كمّل بداية ونهاية الفترة الأولى', OpeningHoursForm::dayError('sun', $day(['slot1_to' => null])));
        $this->assertSame('الأحد: كمّل بداية ونهاية الفترة التانية أو شيلها', OpeningHoursForm::dayError('sun', $day(['has_second' => true])));
        $this->assertSame('الاثنين: كمّل بداية ونهاية الفترة التانية', OpeningHoursForm::dayError('mon', $day(['has_second' => true, 'slot2_from' => '18:00'])));
        $this->assertSame('الجمعة: الفترتين متداخلتين', OpeningHoursForm::dayError('fri', $day([
            'has_second' => true, 'slot2_from' => '13:00', 'slot2_to' => '20:00',
        ])));
        // فترتين متتاليتين من غير تداخل
        $this->assertNull(OpeningHoursForm::dayError('fri', $day([
            'has_second' => true, 'slot2_from' => '14:00', 'slot2_to' => '20:00',
        ])));
        // التداخل بيتفحص جوه اليوم بس: التانية بتعدّي نص الليل ومش بتتقارن بفترات اليوم اللي بعده (قرار 07 القسم 3)
        $this->assertNull(OpeningHoursForm::dayError('thu', $day([
            'slot1_from' => '01:00', 'slot1_to' => '05:00',
            'has_second' => true, 'slot2_from' => '20:00', 'slot2_to' => '03:00',
        ])));
    }

    public function test_copy_day_overwrites_only_the_targets(): void
    {
        $state = OpeningHoursForm::toFormState(null);
        $state['sat'] = ['state' => 'open', 'slot1_from' => '10:00', 'slot1_to' => '22:00', 'has_second' => false, 'slot2_from' => null, 'slot2_to' => null];
        $state['fri']['state'] = 'closed';

        $copied = OpeningHoursForm::copyDay($state, 'sat', OpeningHoursForm::WORKWEEK_DAYS);

        foreach (OpeningHoursForm::WORKWEEK_DAYS as $day) {
            $this->assertSame($state['sat'], $copied[$day]);
        }
        $this->assertSame('closed', $copied['fri']['state'], 'الجمعة ما اتلمسش');

        $all = OpeningHoursForm::copyDay($state, 'sat', OpeningHours::DAYS);
        $this->assertSame($state['sat'], $all['fri']);
    }

    public function test_slot_problem_names_the_first_failure(): void
    {
        $this->assertNull(PlacePublishCheck::slotProblem([['09:00', '23:30']]));
        $this->assertSame(PlacePublishCheck::SLOT_EMPTY, PlacePublishCheck::slotProblem([]));
        $this->assertSame(PlacePublishCheck::SLOT_EMPTY, PlacePublishCheck::slotProblem(null));
        $this->assertSame(PlacePublishCheck::SLOT_TOO_MANY, PlacePublishCheck::slotProblem([['01:00', '02:00'], ['03:00', '04:00'], ['05:00', '06:00']]));
        $this->assertSame(PlacePublishCheck::SLOT_MALFORMED, PlacePublishCheck::slotProblem([['9am', '5pm']]));
        $this->assertSame(PlacePublishCheck::SLOT_EQUAL, PlacePublishCheck::slotProblem([['09:00', '09:00']]));
        $this->assertSame(PlacePublishCheck::SLOT_OVERLAP, PlacePublishCheck::slotProblem([['09:00', '14:00'], ['13:00', '20:00']]));
    }
}
