<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * منطق «مفتوح دلوقتي» (03 القسم 7.1). class نقي من غير داتابيز، والتخزين `opening_hours` بالشكل في 4.4:
 *
 *   { "sat": {"state":"open","slots":[["09:00","23:30"]]}, "mon": {"state":"closed"}, "tue": {"state":"24h"}, ... }
 *
 * الأسبوع بيبدأ السبت (الدقيقة 0)، والدقايق من أول الأسبوع (0 لـ 10079). الفترة اللي «إلى» فيها أصغر
 * من أو بتساوي «من» بتعدّي نص الليل (18:00 لـ 02:00)، واللي بتعدّي آخر الجمعة بتتقسم لصفين.
 */
final class OpeningHours
{
    public const MINUTES_PER_DAY = 1440;

    public const MINUTES_PER_WEEK = 10080;

    /** @var list<string> مفاتيح الأيام بترتيب الأسبوع بدايته السبت */
    public const DAYS = ['sat', 'sun', 'mon', 'tue', 'wed', 'thu', 'fri'];

    /**
     * الفترات المفتوحة كأزواج [بداية، نهاية) بالدقايق من السبت 00:00، من غير فترة بتعدّي آخر الأسبوع.
     *
     * @param  array<string, mixed>|null  $hours
     * @return list<array{0: int, 1: int}>
     */
    public static function intervals(?array $hours): array
    {
        $intervals = [];

        foreach (self::DAYS as $index => $day) {
            $entry = $hours[$day] ?? null;
            if (! is_array($entry)) {
                continue;
            }

            $base = $index * self::MINUTES_PER_DAY;
            $state = $entry['state'] ?? null;

            if ($state === '24h') {
                self::push($intervals, $base, $base + self::MINUTES_PER_DAY);

                continue;
            }

            if ($state !== 'open') {
                continue;
            }

            foreach ($entry['slots'] ?? [] as $slot) {
                if (! is_array($slot) || count($slot) !== 2) {
                    continue;
                }

                $from = self::toMinutes((string) $slot[0]);
                $to = self::toMinutes((string) $slot[1]);
                if ($from === null || $to === null) {
                    continue;
                }

                $start = $base + $from;
                $end = $base + $to;
                if ($end <= $start) {
                    $end += self::MINUTES_PER_DAY;
                }

                self::push($intervals, $start, $end);
            }
        }

        return $intervals;
    }

    /**
     * مفتوح في الدقيقة دي من الأسبوع؟ null لو المواعيد فاضية (المكان ما بيظهرش في فلتر «مفتوح دلوقتي»).
     *
     * @param  array<string, mixed>|null  $hours
     */
    public static function isOpenAt(?array $hours, int $weekMinute): ?bool
    {
        if ($hours === null || $hours === []) {
            return null;
        }

        foreach (self::intervals($hours) as [$start, $end]) {
            if ($weekMinute >= $start && $weekMinute < $end) {
                return true;
            }
        }

        return false;
    }

    /**
     * الدقيقة من أول الأسبوع (السبت 00:00 = 0) للوقت المحلي المعطى. لازم يوصل بتوقيت القاهرة.
     */
    public static function weekMinute(CarbonInterface $local): int
    {
        // Carbon: الأحد 0 ... السبت 6. الأسبوع عندنا بيبدأ السبت.
        $dayIndex = ($local->dayOfWeek + 1) % 7;

        return $dayIndex * self::MINUTES_PER_DAY + $local->hour * 60 + $local->minute;
    }

    private static function toMinutes(string $time): ?int
    {
        if (! preg_match('/^(\d{1,2}):(\d{2})$/', $time, $m)) {
            return null;
        }

        $hours = (int) $m[1];
        $minutes = (int) $m[2];

        return $hours <= 24 && $minutes < 60 ? $hours * 60 + $minutes : null;
    }

    /**
     * @param  list<array{0: int, 1: int}>  $intervals
     */
    private static function push(array &$intervals, int $start, int $end): void
    {
        if ($end > self::MINUTES_PER_WEEK) {
            $intervals[] = [$start, self::MINUTES_PER_WEEK];
            $intervals[] = [0, $end - self::MINUTES_PER_WEEK];

            return;
        }

        $intervals[] = [$start, $end];
    }
}
