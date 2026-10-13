<?php

namespace App\Console\Commands;

use App\Models\AuthCode;
use Illuminate\Console\Command;

/**
 * بيمسح الأكواد المنتهية والمستخدمة (يومياً، 06 القسم 4). بنسيب يوم كامل بعد الانتهاء عشان قاعدة
 * «ابعت تاني بعد 60 ثانية» تفضل شغالة وقت الحدود.
 */
class PruneAuthCodesCommand extends Command
{
    protected $signature = 'auth:prune-codes {--hours=24 : سنّ الكود بالساعات قبل المسح}';

    protected $description = 'Delete expired and used password reset / email verification codes';

    public function handle(): int
    {
        $cutoff = now()->subHours((int) $this->option('hours'));

        $deleted = AuthCode::query()
            ->where(fn ($query) => $query
                ->where('expires_at', '<', $cutoff)
                ->orWhere('consumed_at', '<', $cutoff))
            ->delete();

        $this->info("Deleted {$deleted} auth codes.");

        return self::SUCCESS;
    }
}
