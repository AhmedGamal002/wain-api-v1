<?php

namespace App\Support;

/**
 * جسر بين شكل التخزين لـ `opening_hours` و`ramadan_hours` (03 القسم 4.4) وشكل حقول الـ form في تاب المواعيد.
 *
 * التخزين: `{ "sat": {"state":"open","slots":[["09:00","23:30"],["18:00","02:00"]]}, "mon": {"state":"closed"} }`.
 * الـ form (لكل يوم): `state` و`slot1_from` و`slot1_to` و`has_second` و`slot2_from` و`slot2_to`، لإن Filament
 * محتاج حقول بأسماء مش مصفوفة فترات.
 *
 * مفيش هنا شرط نشر: «كل يوم له حالة وفتراته صحيحة» في `PlacePublishCheck` بس (الشرط 8)، وده بيشتغل على
 * الناتج من `fromFormState()`. هنا بس التحويل والخيارات ورسايل الخطأ بالسبب اللي بيرجّعه `slotProblem`.
 */
final class OpeningHoursForm
{
    /** @var array<string, string> */
    public const DAY_LABELS = [
        'sat' => 'السبت',
        'sun' => 'الأحد',
        'mon' => 'الاثنين',
        'tue' => 'الثلاثاء',
        'wed' => 'الأربعاء',
        'thu' => 'الخميس',
        'fri' => 'الجمعة',
    ];

    /** @var array<string, string> */
    public const STATE_LABELS = [
        'open' => 'مفتوح',
        'closed' => 'مغلق',
        '24h' => '24 ساعة',
    ];

    /** خطوة الاختيار بالدقايق (03 القسم 4.4: «بخطوات 15 دقيقة»). */
    public const STEP_MINUTES = 15;

    /** «انسخ لأيام الأسبوع بس»: من الأحد للخميس، والجمعة والسبت بيفضلوا زي ما هما. */
    public const WORKWEEK_DAYS = ['sun', 'mon', 'tue', 'wed', 'thu'];

    /**
     * أوقات اليوم كل 15 دقيقة: `09:00` => `9:00 ص`. 12 ساعة زي التطبيق (03 القسم 6.6) وبأرقام لاتينية.
     *
     * @return array<string, string>
     */
    public static function timeOptions(): array
    {
        $options = [];

        for ($minute = 0; $minute < OpeningHours::MINUTES_PER_DAY; $minute += self::STEP_MINUTES) {
            $hour = intdiv($minute, 60);
            $display = $hour % 12 === 0 ? 12 : $hour % 12;

            $options[sprintf('%02d:%02d', $hour, $minute % 60)] = sprintf('%d:%02d %s', $display, $minute % 60, $hour < 12 ? 'ص' : 'م');
        }

        return $options;
    }

    /**
     * التخزين ← حقول الـ form. المخزَّن الفاضي أو الناقص بيطلع أيام من غير حالة (الـ form بيعرضها فاضية).
     *
     * @param  array<string, mixed>|null  $hours
     * @return array<string, array<string, mixed>>
     */
    public static function toFormState(?array $hours): array
    {
        $state = [];

        foreach (OpeningHours::DAYS as $day) {
            $entry = is_array($hours[$day] ?? null) ? $hours[$day] : [];
            $slots = is_array($entry['slots'] ?? null) ? array_values($entry['slots']) : [];
            $first = is_array($slots[0] ?? null) ? $slots[0] : [];
            $second = is_array($slots[1] ?? null) ? $slots[1] : [];

            $state[$day] = [
                'state' => in_array($entry['state'] ?? null, array_keys(self::STATE_LABELS), true) ? $entry['state'] : null,
                'slot1_from' => self::time($first[0] ?? null),
                'slot1_to' => self::time($first[1] ?? null),
                'has_second' => $second !== [],
                'slot2_from' => self::time($second[0] ?? null),
                'slot2_to' => self::time($second[1] ?? null),
            ];
        }

        return $state;
    }

    /**
     * حقول الـ form ← التخزين. الأيام من غير حالة بتتشال، وفترة ناقصة (من من غير إلى) بتتشال هي كمان فيطلع
     * اليوم المفتوح من غير فترات وشرط «المواعيد» ما يتحققش. null لو مفيش يوم له حالة.
     *
     * @param  array<string, mixed>|null  $state
     * @return array<string, array<string, mixed>>|null
     */
    public static function fromFormState(?array $state): ?array
    {
        $hours = [];

        foreach (OpeningHours::DAYS as $day) {
            $entry = is_array($state[$day] ?? null) ? $state[$day] : [];
            $dayState = $entry['state'] ?? null;

            if (! in_array($dayState, array_keys(self::STATE_LABELS), true)) {
                continue;
            }

            if ($dayState !== 'open') {
                $hours[$day] = ['state' => $dayState];

                continue;
            }

            $slots = [];
            $pairs = [[$entry['slot1_from'] ?? null, $entry['slot1_to'] ?? null]];
            if (! empty($entry['has_second'])) {
                $pairs[] = [$entry['slot2_from'] ?? null, $entry['slot2_to'] ?? null];
            }

            foreach ($pairs as [$from, $to]) {
                if (self::time($from) !== null && self::time($to) !== null) {
                    $slots[] = [self::time($from), self::time($to)];
                }
            }

            $hours[$day] = ['state' => 'open', 'slots' => $slots];
        }

        return $hours === [] ? null : $hours;
    }

    /**
     * رسالة بتسمّي مشكلة فترات يوم (من حقول الـ form)، أو null لو اليوم سليم أو مش مفتوح.
     *
     * @param  array<string, mixed>  $entry  حقول اليوم في الـ form
     */
    public static function dayError(string $day, array $entry): ?string
    {
        if (($entry['state'] ?? null) !== 'open') {
            return null;
        }

        $label = self::DAY_LABELS[$day] ?? $day;
        $first = [$entry['slot1_from'] ?? null, $entry['slot1_to'] ?? null];
        $second = ! empty($entry['has_second']) ? [$entry['slot2_from'] ?? null, $entry['slot2_to'] ?? null] : null;

        foreach ([[$first, 'الأولى'], [$second, 'التانية']] as [$pair, $ordinal]) {
            if ($pair === null) {
                continue;
            }

            $filled = count(array_filter($pair, fn ($value) => self::time($value) !== null));
            if ($filled === 1) {
                return "{$label}: كمّل بداية ونهاية الفترة {$ordinal}";
            }
        }

        $slots = array_values(array_filter(
            [self::pair($first), $second === null ? null : self::pair($second)],
        ));

        // فترة تانية مفعّلة من غير بيانات: بتتعامل زي فترة ناقصة
        if ($second !== null && self::pair($second) === null) {
            return "{$label}: كمّل بداية ونهاية الفترة التانية أو شيلها";
        }

        return match (PlacePublishCheck::slotProblem($slots)) {
            PlacePublishCheck::SLOT_EQUAL => "{$label}: بداية الفترة زي نهايتها",
            PlacePublishCheck::SLOT_OVERLAP => "{$label}: الفترتين متداخلتين",
            default => null,
        };
    }

    /**
     * نسخ مواعيد يوم على أيام تانية (زرار «انسخ السبت لكل الأيام»).
     *
     * @param  array<string, array<string, mixed>>  $state
     * @param  list<string>  $targets
     * @return array<string, array<string, mixed>>
     */
    public static function copyDay(array $state, string $from, array $targets): array
    {
        $source = $state[$from] ?? null;
        if (! is_array($source)) {
            return $state;
        }

        foreach ($targets as $day) {
            if ($day !== $from && in_array($day, OpeningHours::DAYS, true)) {
                $state[$day] = $source;
            }
        }

        return $state;
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private static function pair(array $values): ?array
    {
        [$from, $to] = [self::time($values[0] ?? null), self::time($values[1] ?? null)];

        return $from !== null && $to !== null ? [$from, $to] : null;
    }

    private static function time(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
