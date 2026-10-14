<?php

namespace App\Jobs;

use App\Events\PlaceChanged;
use App\Models\PlacePhoto;
use App\Support\PlacePhotoProcessor;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * بيعمل نسخ WebP للصورة المرفوعة (03 القسم 4.5): الحالة `processing` ← `ready`، أو `failed` وإشعار للي رفعها.
 *
 * `$makeCover`: الصورة بتبقى غلاف المكان لما تخلص بس (مش وقت الرفع)، فمكان منشور ما بيفضلش من غير غلاف
 * وهو بيتعالج. `PlaceChanged` بيتطلق بعد أي تغيير عشان cache التفاصيل ونتايج البحث تشوف الصورة.
 */
class ProcessPlacePhoto implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(public readonly int $photoId, public readonly bool $makeCover = false) {}

    public function handle(PlacePhotoProcessor $processor): void
    {
        $photo = PlacePhoto::query()->with('place')->find($this->photoId);
        $place = $photo?->place;

        // الصورة أو المكان اتمسحوا قبل ما الـ job يشتغل
        if ($photo === null || $place === null) {
            return;
        }

        $isCover = $this->makeCover || $place->cover_photo_id === $photo->id;

        $old = $photo->replicate();
        $result = $processor->process($photo, cover: $isCover);

        $photo->forceFill([
            'variants' => $result['variants'],
            'width' => min($result['width'], 65535),
            'height' => min($result['height'], 65535),
            'status' => PlacePhoto::STATUS_READY,
        ])->save();

        // أي نسخ قديمة (إعادة المعالجة) اللي مسارها مختلف
        $stale = array_diff((array) $old->variants, $result['variants']);
        if ($stale !== []) {
            $processor->deleteVariants((new PlacePhoto)->forceFill(['variants' => $stale]));
        }

        if ($this->makeCover && $place->cover_photo_id !== $photo->id) {
            $place->cover_photo_id = $photo->id;
            $place->save();           // Observer: PlaceChanged + سجل التغييرات
        } else {
            PlaceChanged::dispatch($place);
        }
    }

    /**
     * الفشل النهائي: الصورة `failed` (زرار «حاول تاني» في اللوحة) وإشعار للي رفعها (03 القسم 4.9).
     */
    public function failed(Throwable $exception): void
    {
        $photo = PlacePhoto::query()->with(['place', 'uploader'])->find($this->photoId);

        if ($photo === null) {
            return;
        }

        $photo->forceFill(['status' => PlacePhoto::STATUS_FAILED])->save();

        report($exception);

        if ($photo->place !== null) {
            PlaceChanged::dispatch($photo->place);
        }

        if ($photo->uploader !== null) {
            Notification::make()
                ->danger()
                ->title('صورة فشلت في المعالجة')
                ->body('«'.($photo->place?->name_ar ?? '').'»: افتح تاب الصور واضغط «حاول تاني».')
                ->sendToDatabase($photo->uploader);
        }
    }
}
