<?php

namespace App\Support;

use App\Models\PlacePhoto;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * نقل صور الأماكن بين disks (03 القسم 4.5: «بينقل كل الصور الموجودة لـ R2 ويولّد النسخ ويمسح EXIF»).
 *
 * المسارات في الداتابيز نسبية، فالنقل نسخ ملفات من الـ disk القديم للـ disk الحالي (`wain.photos.disk` للنسخ
 * و`original_disk` للأصل) من غير ما أي صف يتغيّر. الـ URLs بتتحسب من الـ disk الحالي وقت الطلب
 * (`PlacePhoto::variantUrl`) والـ cache بيخزّن المسارات مش الروابط، فمفيش cache يتمسح بعد التبديل.
 *
 * لكل صورة:
 *  1. الأصل: بيتنسخ للـ disk الحالي لو مش موجود فيه (أو حجمه مختلف).
 *  2. النسخ التلاتة: نفس الكلام. نسخة مفقودة من الاتنين (أو `$reprocess`) بتتولّد من الأصل بـ
 *     `PlacePhotoProcessor` (GD يعيد الترميز فالـ EXIF بيتشال)، والصف بيرجع `ready`.
 *  3. `$deleteSource`: بعد ما الصورة كاملة على الـ disk الحالي بس، بيتمسح القديم (ومش بيتمسح لو الـ disk
 *     القديم هو الحالي).
 *
 * idempotent: ملف موجود بنفس الحجم بيتعدّى، فالـ command ممكن يتشغّل تاني (بعد ما أثناء النقل صور اترفعت على
 * القديم مثلاً). صورة `processing` بتتعدّى (الـ job بتاعها هيكتب على الـ disk الحالي)، وصور البيانات التجريبية
 * (روابط مطلقة) مفيهاش ملفات عندنا فبتتعدّى.
 */
final class PlacePhotoMigrator
{
    public function __construct(private readonly PlacePhotoProcessor $processor) {}

    /**
     * @return array{
     *     photos: int, originals_copied: int, variants_copied: int, reprocessed: int, already: int,
     *     external: int, processing: int, deleted_files: int, failed: array<int, string>
     * }
     */
    public function run(
        string $fromDisk,
        string $fromOriginalDisk,
        bool $dryRun = false,
        bool $reprocess = false,
        bool $deleteSource = false,
        ?int $placeId = null,
    ): array {
        $to = (string) config('wain.photos.disk');
        $toOriginal = (string) config('wain.photos.original_disk');

        $disks = [
            'from' => Storage::disk($fromDisk),
            'to' => Storage::disk($to),
            'from_original' => Storage::disk($fromOriginalDisk),
            'to_original' => Storage::disk($toOriginal),
            // مسح من disk هو نفسه الحالي معناه مسح الصورة كلها
            'delete_variants' => $deleteSource && $fromDisk !== $to,
            'delete_originals' => $deleteSource && $fromOriginalDisk !== $toOriginal,
        ];

        $stats = [
            'photos' => 0, 'originals_copied' => 0, 'variants_copied' => 0, 'reprocessed' => 0, 'already' => 0,
            'external' => 0, 'processing' => 0, 'deleted_files' => 0, 'failed' => [],
        ];

        PlacePhoto::query()
            ->with('place:id,cover_photo_id')
            ->when($placeId !== null, fn ($query) => $query->where('place_id', $placeId))
            ->eachById(function (PlacePhoto $photo) use ($disks, $dryRun, $reprocess, &$stats): void {
                $stats['photos']++;

                try {
                    $this->migrate($photo, $disks, $dryRun, $reprocess, $stats);
                } catch (Throwable $e) {
                    // صورة واحدة بتفشل ما بتوقّفش الباقي، وبتظهر في الملخص (والـ log)
                    $stats['failed'][$photo->id] = $e->getMessage();
                    report($e);
                }
            });

        return $stats;
    }

    /**
     * @param  array<string, mixed>  $disks
     * @param  array<string, mixed>  $stats
     */
    private function migrate(PlacePhoto $photo, array $disks, bool $dryRun, bool $reprocess, array &$stats): void
    {
        if ($photo->status === PlacePhoto::STATUS_PROCESSING) {
            $stats['processing']++;

            return;
        }

        $variants = array_filter((array) $photo->variants, fn ($path) => is_string($path) && $path !== '');

        if ($variants !== [] && collect($variants)->every(fn (string $path) => $this->isUrl($path))) {
            $stats['external']++;

            return;
        }

        // عدد الملفات اللي اتنسخت للصورة دي: صفر ولا reprocess معناه إنها كانت كاملة من الأول
        $original = $this->ensure($disks['from_original'], $disks['to_original'], $photo->path, $dryRun);
        $copied = $original === 'copied' ? 1 : 0;
        $stats['originals_copied'] += $copied;

        $missing = [];
        foreach (array_keys((array) config('wain.photos.widths')) as $name) {
            $path = $variants[$name] ?? null;

            if (! is_string($path)) {
                $missing[] = $name;

                continue;
            }

            if ($this->isUrl($path)) {
                continue;
            }

            $state = $this->ensure($disks['from'], $disks['to'], $path, $dryRun);

            if ($state === 'missing') {
                $missing[] = $name;
            } elseif ($state === 'copied') {
                $stats['variants_copied']++;
                $copied++;
            }
        }

        $reprocessed = false;

        if ($missing !== [] || $reprocess) {
            if ($original === 'missing') {
                throw new RuntimeException('الأصل مش موجود على أي disk'.($missing !== [] ? ' والنسخ ناقصة: '.implode(', ', $missing) : ''));
            }

            if (! $dryRun) {
                $this->reprocess($photo);
            }

            $stats['reprocessed']++;
            $reprocessed = true;
        }

        if (! $reprocessed && $copied === 0) {
            $stats['already']++;
        }

        // وصلنا هنا يعني الصورة كاملة على الـ disk الحالي (أي نقص كان رمى فوق): القديم ممكن يتمسح
        if (! $dryRun) {
            $stats['deleted_files'] += $this->deleteSource($photo, $disks);
        }
    }

    /**
     * يتأكد إن الملف على الـ disk الحالي بنفس حجم القديم، وينسخه لو لأ.
     *
     * @return 'present'|'copied'|'missing'
     */
    private function ensure(Filesystem $from, Filesystem $to, string $path, bool $dryRun): string
    {
        $sourceHas = $from->exists($path);

        if ($to->exists($path) && (! $sourceHas || $to->size($path) === $from->size($path))) {
            return 'present';
        }

        if (! $sourceHas) {
            return 'missing';
        }

        if (! $dryRun) {
            $this->copy($from, $to, $path);
        }

        return 'copied';
    }

    private function copy(Filesystem $from, Filesystem $to, string $path): void
    {
        $stream = $from->readStream($path);

        if (! is_resource($stream)) {
            throw new RuntimeException("مقدرتش أقرا الملف {$path}");
        }

        try {
            $written = $to->writeStream($path, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if ($written === false || $to->size($path) !== $from->size($path)) {
            throw new RuntimeException("نسخ الملف فشل أو حجمه بعد النسخ مختلف: {$path}");
        }
    }

    /** يولّد النسخ من الأصل (على الـ disk الحالي) ويحدّث الصف زي `ProcessPlacePhoto`. */
    private function reprocess(PlacePhoto $photo): void
    {
        $result = $this->processor->process($photo, cover: $photo->place?->cover_photo_id === $photo->id);

        $photo->forceFill([
            'variants' => $result['variants'],
            'width' => min($result['width'], 65535),
            'height' => min($result['height'], 65535),
            'status' => PlacePhoto::STATUS_READY,
        ])->save();
    }

    /**
     * يمسح ملفات الصورة من الـ disks القديمة، بعد ما اتأكدنا إنها كلها على الجديدة.
     *
     * @param  array<string, mixed>  $disks
     */
    private function deleteSource(PlacePhoto $photo, array $disks): int
    {
        $deleted = 0;

        if ($disks['delete_originals'] && $disks['to_original']->exists($photo->path)) {
            $deleted += $disks['from_original']->delete($photo->path) ? 1 : 0;
        }

        if ($disks['delete_variants']) {
            foreach ((array) $photo->variants as $path) {
                if (is_string($path) && ! $this->isUrl($path) && $disks['to']->exists($path)) {
                    $deleted += $disks['from']->delete($path) ? 1 : 0;
                }
            }
        }

        return $deleted;
    }

    private function isUrl(string $path): bool
    {
        return str_starts_with($path, 'http://') || str_starts_with($path, 'https://');
    }
}
