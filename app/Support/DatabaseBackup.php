<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * نسخة احتياطية يومية لقاعدة البيانات على R2 (FRD §8 أسبوع 6: «البيانات اللي بتتدخل أغلى من الكود»).
 *
 * `mariadb-dump --single-transaction` بيتضغط gzip على مرحلة (من غير ما الـ dump كله يتحمّل في الذاكرة)،
 * وبعدها يترفع على disk `wain.backup.disk` تحت `{prefix}/db/YYYY/MM/wain-YYYYmmdd-HHMMSS.sql.gz`، ونتأكد إنه
 * اترفع بالحجم الصح قبل ما نمسح أي نسخة قديمة.
 *
 * الـ dump بـ Symfony Process على `proc_open` (المفتوحة على Hostinger، عكس `exec`)، والباسورد في
 * `MYSQL_PWD` مش في سطر الأمر. الـ `dumper` قابل للاستبدال للتستات (closure بياخد مسار الملف الهدف).
 *
 * @phpstan-type Result array{path: string, bytes: int, pruned: int}
 */
final class DatabaseBackup
{
    /** جداول مش محتاجة نسخة: جلسات وcache. */
    private const SKIP_TABLES = ['sessions', 'cache', 'cache_locks'];

    /** @param  (Closure(string): void)|null  $dumper */
    public function __construct(private readonly ?Closure $dumper = null) {}

    /**
     * @return array{path: string, bytes: int, pruned: int}
     */
    public function run(?CarbonInterface $now = null): array
    {
        $now ??= now('UTC');
        $disk = Storage::disk((string) config('wain.backup.disk'));

        $temp = tempnam(sys_get_temp_dir(), 'wain-backup-');
        if ($temp === false) {
            throw new RuntimeException('مقدرتش أعمل ملف مؤقت للنسخة');
        }

        try {
            ($this->dumper ?? fn (string $target) => $this->dump($target))($temp);

            $bytes = (int) filesize($temp);
            if ($bytes < (int) config('wain.backup.min_bytes')) {
                throw new RuntimeException("النسخة صغيرة بشكل مريب ({$bytes} بايت): الـ dump غالباً فشل");
            }

            $path = $this->path($now);
            $stream = fopen($temp, 'rb');
            if ($stream === false) {
                throw new RuntimeException('مقدرتش أقرا الملف المؤقت للنسخة');
            }

            try {
                $stored = $disk->put($path, $stream);
            } finally {
                fclose($stream);
            }

            if ($stored === false || ! $disk->exists($path) || $disk->size($path) !== $bytes) {
                throw new RuntimeException("النسخة ما اترفعتش كاملة على {$path}");
            }
        } finally {
            @unlink($temp);
        }

        return ['path' => $path, 'bytes' => $bytes, 'pruned' => $this->prune($now)];
    }

    /** `{prefix}/db/2026/11/wain-20261122-031000.sql.gz`. */
    public function path(CarbonInterface $now): string
    {
        $prefix = (string) config('wain.backup.prefix');

        return sprintf('%s/db/%s/wain-%s.sql.gz', $prefix, $now->format('Y/m'), $now->format('Ymd-His'));
    }

    /**
     * يمسح النسخ الأقدم من `keep_days`، ويسيب `min_keep` نسخ على الأقل (الأحدث) مهما كان.
     */
    private function prune(CarbonInterface $now): int
    {
        $disk = Storage::disk((string) config('wain.backup.disk'));
        $cutoff = $now->copy()->subDays((int) config('wain.backup.keep_days'))->getTimestamp();
        $minKeep = max(1, (int) config('wain.backup.min_keep'));

        $files = [];
        foreach ($disk->allFiles((string) config('wain.backup.prefix').'/db') as $file) {
            if (str_ends_with($file, '.sql.gz')) {
                $files[$file] = $disk->lastModified($file);
            }
        }

        arsort($files);
        $keep = array_slice(array_keys($files), 0, $minKeep);

        $pruned = 0;
        foreach ($files as $file => $modified) {
            if ($modified < $cutoff && ! in_array($file, $keep, true)) {
                $disk->delete($file);
                $pruned++;
            }
        }

        return $pruned;
    }

    private function dump(string $target): void
    {
        $connection = config('database.connections.'.config('database.default'));

        $command = [
            (string) config('wain.backup.dump_binary'),
            '--single-transaction', '--quick', '--routines', '--triggers',
            '--default-character-set=utf8mb4',
            '--host='.($connection['host'] ?? '127.0.0.1'),
            '--port='.($connection['port'] ?? 3306),
            '--user='.($connection['username'] ?? ''),
        ];

        foreach (self::SKIP_TABLES as $table) {
            $command[] = '--ignore-table='.$connection['database'].'.'.$table;
        }

        $command[] = (string) $connection['database'];

        $gz = gzopen($target, 'wb6');
        if ($gz === false) {
            throw new RuntimeException('مقدرتش أفتح الملف المؤقت للكتابة');
        }

        $errors = '';
        $process = new Process($command, null, ['MYSQL_PWD' => (string) ($connection['password'] ?? '')], null, 3600);

        try {
            $process->run(function (string $type, string $buffer) use ($gz, &$errors) {
                if ($type === Process::OUT) {
                    gzwrite($gz, $buffer);
                } else {
                    $errors .= $buffer;
                }
            });
        } finally {
            gzclose($gz);
        }

        if (! $process->isSuccessful()) {
            throw new RuntimeException('فشل الـ dump (كود '.$process->getExitCode().'): '.trim($errors));
        }
    }
}
