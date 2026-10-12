<?php

namespace App\Support;

use App\Models\Place;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * «دلوقتي» بتوقيت القاهرة: الدقيقة من أول الأسبوع وهل إحنا في رمضان. بيتعمل مرة في الـ request
 * وبيتمرّر للكروت والفلتر، فنفس اللحظة بتحكم القائمة والفلتر.
 *
 * التوقيت الصيفي بيتحسب من قاعدة المناطق الزمنية في PHP (`Africa/Cairo`) مش من فرق ثابت من UTC.
 */
final class OpenNow
{
    public const TIMEZONE = 'Africa/Cairo';

    public function __construct(
        public readonly int $weekMinute,
        public readonly bool $ramadan,
    ) {}

    public static function at(?CarbonInterface $moment = null): self
    {
        $local = Carbon::instance($moment ?? now())->timezone(self::TIMEZONE);

        return new self(OpeningHours::weekMinute($local), self::isRamadan($local));
    }

    /**
     * مفتوح دلوقتي؟ null لو المواعيد فاضية. جوه رمضان وللمكان مواعيد رمضان بتتستخدم هي، وإلا العادية.
     */
    public function isOpen(Place $place): ?bool
    {
        return OpeningHours::isOpenAt($this->hoursFor($place), $this->weekMinute);
    }

    /**
     * المواعيد اللي ماشية دلوقتي للمكان: مواعيد رمضان لو إحنا في رمضان وللمكان مواعيد رمضان، وإلا العادية.
     *
     * @return array<string, mixed>|null
     */
    public function hoursFor(Place $place): ?array
    {
        return $this->usesRamadanHours($place) ? $place->ramadan_hours : $place->opening_hours;
    }

    public function usesRamadanHours(Place $place): bool
    {
        return $this->ramadan && ! empty($place->ramadan_hours);
    }

    /**
     * رمضان من الإعدادات `ramadan_starts_at` و`ramadan_ends_at` (تاريخين، الأطراف داخلة).
     */
    private static function isRamadan(CarbonInterface $local): bool
    {
        $settings = DB::table('settings')
            ->whereIn('key', ['ramadan_starts_at', 'ramadan_ends_at'])
            ->pluck('value', 'key');

        $starts = $settings['ramadan_starts_at'] ?? null;
        $ends = $settings['ramadan_ends_at'] ?? null;
        if (! $starts || ! $ends) {
            return false;
        }

        $today = $local->toDateString();

        return $today >= $starts && $today <= $ends;
    }
}
