<?php

namespace App\Support;

use App\Models\PlaceEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * التجميع الليلي لـ `place_events` في `place_stats_daily` (03 القسم 2): صف لكل (مكان، يوم، نوع) بعدد الأحداث.
 * الأحداث الخام بتتمسح بعد 90 يوم (`events:prune`) والمتجمّع بيفضل، فالإحصائيات الأقدم تفضل موجودة.
 *
 * - **اليوم بتوقيت القاهرة** (الإحصائيات بتتقرا بيه في اللوحة)، والأحداث مخزّنة UTC، فحدود اليوم بتتحوّل
 *   لـ UTC بقاعدة المناطق الزمنية (يوم التوقيت الصيفي 23 أو 25 ساعة).
 * - **idempotent:** الصف بيتكتب بـ upsert بالعدد الكامل (مش بيزوّد)، فإعادة التجميع لنفس اليوم بتدّي نفس
 *   النتيجة، وأحداث اتأخّرت في الـ queue لحد بعد ما اليوم اتجمّع بتتحسب في التجميع اللي بعده (الـ command
 *   بيعيد يوم أمس وقبله بشكل افتراضي).
 * - اليوم اللي بدايته أقدم من فترة الاحتفاظ ما بيتجمّعش تاني: أحداثه الخام اتمسحت جزئياً، والتجميع كان هيقلّل
 *   العدد الصح اللي متخزّن.
 */
final class PlaceStatsAggregator
{
    private const TIMEZONE = 'Africa/Cairo';

    /**
     * بيجمّع يوم واحد بتوقيت القاهرة (`Y-m-d`).
     *
     * @return bool false لو اليوم برّه فترة الاحتفاظ (ما اتجمّعش)
     */
    public function aggregateDay(string $date, ?Carbon $now = null): bool
    {
        [$from, $to] = $this->bounds($date);

        $retentionStart = ($now ?? now())->copy()->subDays(PlaceEvent::RETENTION_DAYS);

        if ($from->lt($retentionStart)) {
            return false;
        }

        DB::affectingStatement(
            'INSERT INTO place_stats_daily (place_id, `date`, type, `count`)
             SELECT place_id, ?, type, COUNT(*) FROM place_events
             WHERE created_at >= ? AND created_at < ?
             GROUP BY place_id, type
             ON DUPLICATE KEY UPDATE `count` = VALUES(`count`)',
            [$date, $from->toDateTimeString(), $to->toDateTimeString()],
        );

        return true;
    }

    /**
     * أيام القاهرة الكاملة الأخيرة، من الأحدث (أمس) لورا.
     *
     * @return list<string>
     */
    public function lastDays(int $days, ?Carbon $now = null): array
    {
        $today = ($now ?? now())->copy()->timezone(self::TIMEZONE)->startOfDay();

        return array_map(
            fn (int $back) => $today->copy()->subDays($back)->toDateString(),
            range(1, max(1, $days)),
        );
    }

    /**
     * بداية اليوم ونهايته (حصرياً) بالـ UTC لتاريخ القاهرة.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function bounds(string $date): array
    {
        $start = Carbon::parse($date, self::TIMEZONE)->startOfDay();
        $next = Carbon::parse($date, self::TIMEZONE)->addDay()->startOfDay();

        return [$start->copy()->utc(), $next->copy()->utc()];
    }
}
