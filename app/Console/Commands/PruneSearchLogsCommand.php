<?php

namespace App\Console\Commands;

use App\Models\SearchLog;
use Illuminate\Console\Command;

/**
 * بيمسح سجل البحث الأقدم من 90 يوم (يومياً، 06 القسم 4): كلمات البحث بيانات المستخدمين، فمش بنخزّنها أكتر
 * من اللازم لتقارير «أكتر بحث» و«بحث من غير نتائج».
 */
class PruneSearchLogsCommand extends Command
{
    public const RETENTION_DAYS = 90;

    protected $signature = 'search:prune-logs {--days=90 : الأقدم من كام يوم يتمسح}';

    protected $description = 'Delete search logs older than the retention period (90 days)';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));

        $deleted = SearchLog::query()->where('created_at', '<', now()->subDays($days))->delete();

        $this->info("Deleted {$deleted} search logs older than {$days} days.");

        return self::SUCCESS;
    }
}
