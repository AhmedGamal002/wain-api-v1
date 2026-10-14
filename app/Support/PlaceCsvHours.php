<?php

namespace App\Support;

/**
 * خلية مواعيد يوم واحد في ملف الـ CSV ↔ شكل التخزين (`opening_hours`، 03 القسم 4.4). الشكل المكتوب في الخلية:
 *
 *   `09:00-23:30`                فترة واحدة
 *   `09:00-14:00;18:00-02:00`    فترتين (الثانية بعد نص الليل: «إلى» الأصغر من «من»)
 *   `closed` أو `مغلق`            اليوم مقفول
 *   `24h` أو `24 ساعة`            24 ساعة
 *
 * بيتقبل كمان «9:00 ص - 11:30 م» و«9am-11pm» (12 ساعة)، والأرقام العربية. قواعد الفترات (من ≠ إلى، وفترتين على
 * الأكتر من غير تداخل) من `PlacePublishCheck::slotProblem`، نفس الشرط 8 في اللوحة.
 */
final class PlaceCsvHours
{
    private const CLOSED = ['closed', 'مغلق', 'مقفول', 'اجازه', 'اجازة', 'إجازة', 'off'];

    private const DIGITS = [
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
    ];

    /**
     * @return array{value: ?array<string, mixed>, error: ?string} الفاضي `value` بـ null من غير خطأ (اليوم من غير حالة)
     */
    public static function parseDay(string $cell): array
    {
        $text = mb_strtolower(trim(strtr($cell, self::DIGITS)));

        if ($text === '') {
            return ['value' => null, 'error' => null];
        }

        if (in_array($text, self::CLOSED, true)) {
            return ['value' => ['state' => 'closed'], 'error' => null];
        }

        if (preg_match('/^24\s*(?:h|hr|hrs|hours?|ساعه|ساعة|\/7)?$/u', $text) === 1) {
            return ['value' => ['state' => '24h'], 'error' => null];
        }

        $slots = [];

        foreach (preg_split('/[;|،]/u', $text) ?: [] as $part) {
            $part = trim($part);

            if ($part === '') {
                continue;
            }

            $slot = self::slot($part);

            if ($slot === null) {
                return ['value' => null, 'error' => "صيغة المواعيد «{$cell}» مش مفهومة. اكتب 09:00-23:30 أو closed أو 24h"];
            }

            $slots[] = $slot;
        }

        $problem = PlacePublishCheck::slotProblem($slots);

        if ($problem !== null) {
            return ['value' => null, 'error' => match ($problem) {
                PlacePublishCheck::SLOT_EMPTY => 'لازم فترة واحدة على الأقل',
                PlacePublishCheck::SLOT_TOO_MANY => 'أقصى فترتين في اليوم',
                PlacePublishCheck::SLOT_EQUAL => "بداية الفترة زي نهايتها في «{$cell}»",
                PlacePublishCheck::SLOT_OVERLAP => "الفترتين متداخلتين في «{$cell}»",
                default => "وقت غلط في «{$cell}»",
            }];
        }

        return ['value' => ['state' => 'open', 'slots' => $slots], 'error' => null];
    }

    /**
     * المخزَّن ← نص الخلية (للتصدير): الفاضي ← فاضي، وغير المفهوم ← فاضي.
     *
     * @param  array<string, mixed>|null  $entry  `opening_hours[day]`
     */
    public static function format(?array $entry): string
    {
        $state = $entry['state'] ?? null;

        if ($state === 'closed') {
            return 'closed';
        }

        if ($state === '24h') {
            return '24h';
        }

        if ($state !== 'open') {
            return '';
        }

        $slots = [];

        foreach ((array) ($entry['slots'] ?? []) as $slot) {
            if (is_array($slot) && is_string($slot[0] ?? null) && is_string($slot[1] ?? null)) {
                $slots[] = self::pad($slot[0]).'-'.self::pad($slot[1]);
            }
        }

        return implode(PlaceCsv::LIST_SEPARATOR, $slots);
    }

    /**
     * «من-إلى» بصيغة `HH:MM`، أو null لو مش مفهومة.
     *
     * @return array{0: string, 1: string}|null
     */
    private static function slot(string $part): ?array
    {
        $time = '(\d{1,2})(?::(\d{2}))?\s*(am|pm|ص|م)?';

        if (preg_match("/^{$time}\\s*(?:-|–|—|to|الى|إلى)\\s*{$time}$/u", $part, $m) !== 1) {
            return null;
        }

        $from = self::time((int) $m[1], $m[2] ?? '', $m[3] ?? '');
        $to = self::time((int) $m[4], $m[5] ?? '', $m[6] ?? '');

        return $from === null || $to === null ? null : [$from, $to];
    }

    private static function time(int $hour, string $minutes, string $meridiem): ?string
    {
        // «9-5» من غير دقايق ولا ص/م مش مفهومة (9 الصبح لـ 5 العصر ولا 5 الفجر؟): نطلب صيغة واضحة بدل ما نخمّن
        if ($minutes === '' && $meridiem === '') {
            return null;
        }

        $minute = $minutes === '' ? 0 : (int) $minutes;

        if ($meridiem !== '') {
            if ($hour < 1 || $hour > 12) {
                return null;
            }

            $isPm = in_array($meridiem, ['pm', 'م'], true);
            $hour = $isPm ? ($hour % 12) + 12 : $hour % 12;
        }

        // 24:00 مسموحة كنهاية فترة بس (منتصف الليل)
        if ($hour > 24 || $minute > 59 || ($hour === 24 && $minute !== 0)) {
            return null;
        }

        return sprintf('%02d:%02d', $hour, $minute);
    }

    private static function pad(string $time): string
    {
        return preg_match('/^(\d{1,2}):(\d{2})/', $time, $m) === 1 ? sprintf('%02d:%s', (int) $m[1], $m[2]) : $time;
    }
}
