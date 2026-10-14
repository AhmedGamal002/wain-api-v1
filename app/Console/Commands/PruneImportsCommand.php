<?php

namespace App\Console\Commands;

use App\Models\PlaceImport;
use Illuminate\Console\Command;

/**
 * بيمسح رفعات CSV الأماكن القديمة (A10): معاينة ما اتأكّدتش، ونتايج استيراد خلصت. السطور (فيها بيانات الأماكن
 * كلها) مفيش سبب نحتفظ بيها بعد المدة، والأماكن نفسها اتضافت وملف الأخطاء متحمّل قبل كده.
 */
class PruneImportsCommand extends Command
{
    protected $signature = 'places:prune-imports {--days=30 : الأقدم من كام يوم يتمسح}';

    protected $description = 'Delete place CSV imports (previews and results) older than the retention period';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));

        // استيراد شغّال دلوقتي ما يتمسحش حتى لو قديم (job عالق بيتعلّم failed من الـ queue)
        $deleted = PlaceImport::query()
            ->where('created_at', '<', now()->subDays($days))
            ->where('status', '!=', PlaceImport::STATUS_IMPORTING)
            ->delete();

        $this->info("Deleted {$deleted} place imports older than {$days} days.");

        return self::SUCCESS;
    }
}
