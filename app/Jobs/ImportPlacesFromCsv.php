<?php

namespace App\Jobs;

use App\Models\PlaceImport;
use App\Support\PlaceCsvImporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * بيضيف أماكن ملف CSV اللي اتأكّد كمسودات (A10، 02: «الاستيراد بيشتغل في queue»). الاستيراد نفسه في
 * `PlaceCsvImporter::run`، وبيكمّل من حيث وقف لو الـ job اتقطع.
 *
 * محاولة واحدة: لو فشل نهائياً الاستيراد بيتعلّم `failed` والأماكن اللي اتضافت قبل الفشل بتفضل (السطور اللي
 * اتحفظ لها `result`)، وإعادة الاستيراد على نفس الرفعة مش بتكرّرها.
 */
class ImportPlacesFromCsv implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** 500 مكان بكل listeners الحفظ (البحث والمواعيد والـ cache) بيخلصوا في دقيقة أو اتنين. */
    public int $timeout = 900;

    public function __construct(public readonly int $importId) {}

    public function handle(PlaceCsvImporter $importer): void
    {
        $import = PlaceImport::query()->find($this->importId);

        // اتلغى أو اتمسح، أو اتنفّذ قبل كده
        if ($import === null || ! $import->isImporting()) {
            return;
        }

        $importer->run($import);
    }

    public function failed(Throwable $exception): void
    {
        report($exception);

        $import = PlaceImport::query()->find($this->importId);

        if ($import === null || $import->isFinished()) {
            return;
        }

        $import->forceFill([
            'status' => PlaceImport::STATUS_FAILED,
            'error_message' => 'حصل خطأ وقف الاستيراد. الأماكن اللي اتضافت قبل الخطأ موجودة كمسودات، وملف الأخطاء بيحتوي الباقي.',
            'finished_at' => now(),
        ])->save();

        PlaceCsvImporter::notify($import);
    }
}
