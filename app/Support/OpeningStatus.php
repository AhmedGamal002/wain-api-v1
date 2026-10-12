<?php

namespace App\Support;

/**
 * سطر الحالة في صفحة المكان (03 القسم 7.1). بيتحسب في السيرفر عشان المنطق يبقى في مكان واحد.
 *
 * | الحالة                              | النص                         | state          |
 * | 24 ساعة                              | مفتوح 24 ساعة                | open_24h       |
 * | مفتوح وباقي أكتر من 30 دقيقة          | مفتوح · بيقفل 11:30 م        | open           |
 * | مفتوح وباقي 30 دقيقة أو أقل           | بيقفل قريب · 11:30 م         | closing_soon   |
 * | مقفول وهيفتح النهارده                 | مقفول · بيفتح 6:00 م         | closed         |
 * | مقفول وهيفتح بكرة                    | مقفول · بيفتح بكرة 9:00 ص    | closed         |
 * | مقفول وهيفتح بعد بكرة أو أبعد        | مقفول · بيفتح السبت 9:00 ص   | closed         |
 *
 * اللون (أخضر/برتقالي/رمادي) قرار الموبايل من الـ `state`. النصوص من `lang/{ar,en}/hours.php`.
 */
final class OpeningStatus
{
    public const CLOSING_SOON_MINUTES = 30;

    public const STATE_OPEN_24H = 'open_24h';

    public const STATE_OPEN = 'open';

    public const STATE_CLOSING_SOON = 'closing_soon';

    public const STATE_CLOSED = 'closed';

    /**
     * @param  array<string, mixed>|null  $hours  opening_hours أو ramadan_hours اللي ماشية دلوقتي
     * @param  int  $weekMinute  الدقيقة من أول الأسبوع (السبت 00:00 = 0) بتوقيت القاهرة
     * @return array{state: string, text: string, is_open_now: bool}|null null لو المواعيد فاضية
     */
    public static function describe(?array $hours, int $weekMinute): ?array
    {
        if ($hours === null || $hours === []) {
            return null;
        }

        $week = OpeningHours::MINUTES_PER_WEEK;
        $day = OpeningHours::MINUTES_PER_DAY;
        $todayIndex = intdiv($weekMinute, $day);
        $todayState = is_array($hours[OpeningHours::DAYS[$todayIndex]] ?? null)
            ? ($hours[OpeningHours::DAYS[$todayIndex]]['state'] ?? null)
            : null;

        $periods = self::mergedPeriods(OpeningHours::intervals($hours));

        foreach ($periods as [$start, $end]) {
            $offset = self::offsetInside($start, $end, $weekMinute);
            if ($offset === null) {
                continue;
            }

            $minutesLeft = $end - $offset;

            // 24 ساعة النهارده، أو مفتوح طول الأسبوع من غير ما يقفل أبداً
            if ($todayState === '24h' || $minutesLeft >= $week) {
                return self::result(self::STATE_OPEN_24H, __('hours.open_24h'), true);
            }

            $closesAt = self::formatTime($end % $day);
            $closingSoon = $minutesLeft <= self::CLOSING_SOON_MINUTES;

            return $closingSoon
                ? self::result(self::STATE_CLOSING_SOON, __('hours.closing_soon', ['time' => $closesAt]), true)
                : self::result(self::STATE_OPEN, __('hours.open_until', ['time' => $closesAt]), true);
        }

        return self::closedStatus($periods, $weekMinute, $todayIndex);
    }

    /**
     * @param  list<array{0: int, 1: int}>  $periods
     * @return array{state: string, text: string, is_open_now: bool}
     */
    private static function closedStatus(array $periods, int $weekMinute, int $todayIndex): array
    {
        if ($periods === []) {
            return self::result(self::STATE_CLOSED, __('hours.closed'), false);
        }

        $week = OpeningHours::MINUTES_PER_WEEK;
        $day = OpeningHours::MINUTES_PER_DAY;

        // أقرب بداية فترة قدّام (دايري على الأسبوع)
        $delta = min(array_map(fn (array $p) => (($p[0] - $weekMinute) % $week + $week) % $week, $periods));
        $opensAt = ($weekMinute + $delta) % $week;
        $daysAhead = intdiv($weekMinute % $day + $delta, $day);
        $time = self::formatTime($opensAt % $day);

        $text = match (true) {
            $daysAhead === 0 => __('hours.closed_opens_today', ['time' => $time]),
            $daysAhead === 1 => __('hours.closed_opens_tomorrow', ['time' => $time]),
            default => __('hours.closed_opens_day', [
                'day' => __('hours.days.'.OpeningHours::DAYS[($todayIndex + $daysAhead) % 7]),
                'time' => $time,
            ]),
        };

        return self::result(self::STATE_CLOSED, $text, false);
    }

    /**
     * بيدمج الفترات المتلاصقة (إمبارح بتقفل 02:00 واليوم 24 ساعة من 00:00) في فترة واحدة، عشان «بيقفل
     * الساعة كام» يبقى وقت القفل الحقيقي. اللي بتعدّي آخر الأسبوع بتتكمّل بعد 10080.
     *
     * @param  list<array{0: int, 1: int}>  $intervals
     * @return list<array{0: int, 1: int}>
     */
    private static function mergedPeriods(array $intervals): array
    {
        if ($intervals === []) {
            return [];
        }

        usort($intervals, fn (array $a, array $b) => $a[0] <=> $b[0]);

        $merged = [];
        foreach ($intervals as [$start, $end]) {
            $lastIndex = array_key_last($merged);
            if ($lastIndex !== null && $start <= $merged[$lastIndex][1]) {
                $merged[$lastIndex][1] = max($merged[$lastIndex][1], $end);
            } else {
                $merged[] = [$start, $end];
            }
        }

        // آخر فترة بتنتهي عند نهاية الأسبوع وأول فترة بتبدأ من أوله = فترة واحدة بتعدّي الحد
        $lastIndex = array_key_last($merged);
        if (count($merged) > 1 && $merged[0][0] === 0 && $merged[$lastIndex][1] === OpeningHours::MINUTES_PER_WEEK) {
            $merged[$lastIndex][1] = OpeningHours::MINUTES_PER_WEEK + $merged[0][1];
            array_shift($merged);
        }

        return $merged;
    }

    /**
     * لو الدقيقة جوه الفترة بيرجع موقعها كامتداد من بداية الأسبوع (ممكن يعدّي 10080)، وإلا null.
     */
    private static function offsetInside(int $start, int $end, int $weekMinute): ?int
    {
        foreach ([$weekMinute, $weekMinute + OpeningHours::MINUTES_PER_WEEK] as $candidate) {
            if ($candidate >= $start && $candidate < $end) {
                return $candidate;
            }
        }

        return null;
    }

    private static function formatTime(int $minuteOfDay): string
    {
        $hours24 = intdiv($minuteOfDay, 60);
        $minutes = $minuteOfDay % 60;
        $hours12 = $hours24 % 12 === 0 ? 12 : $hours24 % 12;

        return sprintf('%d:%02d %s', $hours12, $minutes, __($hours24 < 12 ? 'hours.am' : 'hours.pm'));
    }

    /**
     * @return array{state: string, text: string, is_open_now: bool}
     */
    private static function result(string $state, string $text, bool $open): array
    {
        return ['state' => $state, 'text' => $text, 'is_open_now' => $open];
    }
}
