<?php

namespace App\Console\Commands;

use App\Models\PlaceEvent;
use Illuminate\Console\Command;

/**
 * بيمسح أحداث الموبايل الخام الأقدم من 90 يوم (يومياً، 06 القسم 4، 03 القسم 5.5): معرّف الجهاز بيانات
 * مستخدم فمش بنحتفظ بيه أكتر من اللازم. المتجمّع في `place_stats_daily` بيفضل.
 */
class PruneEventsCommand extends Command
{
    /** الصفوف في كل DELETE، عشان الـ transaction والـ lock يفضلوا صغيرين لما الجدول يكبر. */
    private const CHUNK = 5000;

    protected $signature = 'events:prune {--days=90 : الأقدم من كام يوم يتمسح}';

    protected $description = 'Delete raw place events older than the retention period (90 days)';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $cutoff = now()->subDays($days);
        $deleted = 0;

        do {
            $batch = PlaceEvent::query()->where('created_at', '<', $cutoff)->limit(self::CHUNK)->delete();
            $deleted += $batch;
        } while ($batch === self::CHUNK);

        $this->info("Deleted {$deleted} place events older than {$days} days.");

        return self::SUCCESS;
    }
}
