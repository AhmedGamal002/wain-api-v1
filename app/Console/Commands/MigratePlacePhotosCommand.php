<?php

namespace App\Console\Commands;

use App\Support\PlacePhotoMigrator;
use Illuminate\Console\Command;

/**
 * نقل صور الأماكن لـ R2 (أسبوع 9، 03 القسم 4.5). التفاصيل في `PlacePhotoMigrator`. بيتشغّل مرة بعد ما
 * `PLACE_PHOTOS_DISK` و`PLACE_PHOTOS_ORIGINAL_DISK` يتبدّلوا في `.env`، وممكن يتكرر من غير ضرر.
 */
class MigratePlacePhotosCommand extends Command
{
    protected $signature = 'photos:migrate
        {--from-disk=public : disk النسخ القديم}
        {--from-original-disk=local : disk الأصل القديم}
        {--place= : مكان واحد بالـ id بدل الكل}
        {--reprocess : يولّد النسخ من الأصل تاني حتى لو موجودة (EXIF والقص)}
        {--delete-source : يمسح من القديم بعد ما الصورة تبقى كاملة على الجديد}
        {--dry-run : يعدّ اللي هيتعمل من غير ما يكتب أو يمسح}';

    protected $description = 'Copy place photos (originals and WebP variants) from the old disks to the configured ones (R2)';

    public function handle(PlacePhotoMigrator $migrator): int
    {
        $fromDisk = (string) $this->option('from-disk');
        $fromOriginalDisk = (string) $this->option('from-original-disk');
        $dryRun = (bool) $this->option('dry-run');
        $place = $this->option('place');

        $to = (string) config('wain.photos.disk');
        $toOriginal = (string) config('wain.photos.original_disk');

        $this->line("النسخ:  {$fromDisk} ← {$to}");
        $this->line("الأصل:  {$fromOriginalDisk} ← {$toOriginal}");

        if ($fromDisk === $to && $fromOriginalDisk === $toOriginal) {
            $this->warn('الـ disks القديمة هي الحالية: مفيش نقل، هيتأكد بس إن كل صورة كاملة ويولّد الناقص.');
        }

        if ($dryRun) {
            $this->warn('dry-run: مفيش حاجة هتتكتب أو تتمسح.');
        }

        $stats = $migrator->run(
            fromDisk: $fromDisk,
            fromOriginalDisk: $fromOriginalDisk,
            dryRun: $dryRun,
            reprocess: (bool) $this->option('reprocess'),
            deleteSource: (bool) $this->option('delete-source'),
            placeId: is_numeric($place) ? (int) $place : null,
        );

        $this->table(['البند', 'العدد'], [
            ['صور اتفحصت', $stats['photos']],
            ['كاملة من الأول', $stats['already']],
            ['أصول '.($dryRun ? 'هتتنسخ' : 'اتنسخت'), $stats['originals_copied']],
            ['نسخ WebP '.($dryRun ? 'هتتنسخ' : 'اتنسخت'), $stats['variants_copied']],
            ['اتولّدت من الأصل', $stats['reprocessed']],
            ['بتتعالج دلوقتي (اتعدّت)', $stats['processing']],
            ['بيانات تجريبية بروابط (اتعدّت)', $stats['external']],
            ['ملفات اتمسحت من القديم', $stats['deleted_files']],
            ['فشلت', count($stats['failed'])],
        ]);

        foreach ($stats['failed'] as $id => $reason) {
            $this->error("صورة {$id}: {$reason}");
        }

        if ($stats['failed'] !== []) {
            return self::FAILURE;
        }

        $this->info($dryRun ? 'جاهز: شغّله من غير --dry-run.' : 'خلص.');

        return self::SUCCESS;
    }
}
