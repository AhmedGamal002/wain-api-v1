<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * بيمسح ملفات الـ cache اللي انتهت (يومياً). الـ file cache مبيمسحش الملف المنتهي غير لما حد يقراه، ومفاتيح قوايم
 * الأماكن فيها رقم نسخة (`places:list:v{version}:…`) بيتغيّر مع أي تعديل، فالنسخ القديمة عمرها ما بتتقرا تاني
 * وكانت هتتراكم على الديسك.
 *
 * كل ملف بيبدأ بـ 10 أرقام = وقت الانتهاء (unix)، وبنقراها من غير ما نفك باقي المحتوى.
 */
class PruneExpiredCacheCommand extends Command
{
    protected $signature = 'cache:prune-expired';

    protected $description = 'Delete expired entries of the file cache store';

    public function handle(): int
    {
        $path = config('cache.stores.file.path');

        if (! is_string($path) || ! is_dir($path)) {
            $this->info('No file cache directory, nothing to prune.');

            return self::SUCCESS;
        }

        // `now()` مش `time()`: نفس الساعة اللي الـ cache نفسه بيحسب بيها الانتهاء (Carbon)
        $now = now()->getTimestamp();
        $deleted = 0;
        $kept = 0;

        foreach (File::allFiles($path) as $file) {
            $handle = @fopen($file->getPathname(), 'rb');

            if ($handle === false) {
                continue;
            }

            $head = (string) fread($handle, 10);
            fclose($handle);

            if (preg_match('/^\d{10}$/', $head) === 1 && (int) $head < $now) {
                $deleted += @unlink($file->getPathname()) ? 1 : 0;

                continue;
            }

            $kept++;
        }

        $this->info("Deleted {$deleted} expired cache files, kept {$kept}.");

        return self::SUCCESS;
    }
}
