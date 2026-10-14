<?php

namespace App\Console\Commands;

use App\Support\PlaceStatsAggregator;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * تجميع أحداث الموبايل في `place_stats_daily` (يومياً بعد منتصف الليل، 06 القسم 4). بيجمّع يوم أمس وقبله
 * بشكل افتراضي (الأحداث اللي اتأخّرت في الـ queue، أو cron فاته ليلة)، و`--date` ليوم بعينه.
 */
class AggregatePlaceStatsCommand extends Command
{
    protected $signature = 'places:aggregate-stats
        {--date= : يوم واحد بتوقيت القاهرة (Y-m-d) بدل آخر أيام}
        {--days=2 : عدد أيام القاهرة الكاملة الأخيرة (أمس وورا)}';

    protected $description = 'Roll the raw place events up into the daily stats table (Cairo days)';

    public function handle(PlaceStatsAggregator $aggregator): int
    {
        $date = $this->option('date');

        if ($date !== null) {
            if (! $this->isDate((string) $date)) {
                $this->error('--date لازم يبقى تاريخ بصيغة Y-m-d.');

                return self::INVALID;
            }

            $days = [(string) $date];
        } else {
            $days = $aggregator->lastDays((int) $this->option('days'));
        }

        foreach ($days as $day) {
            if ($aggregator->aggregateDay($day)) {
                $this->info("Aggregated {$day}.");
            } else {
                $this->warn("Skipped {$day}: older than the raw events retention, its raw events are already (partly) deleted.");
            }
        }

        return self::SUCCESS;
    }

    private function isDate(string $value): bool
    {
        try {
            return Carbon::createFromFormat('!Y-m-d', $value)->toDateString() === $value;
        } catch (Throwable) {
            return false;
        }
    }
}
