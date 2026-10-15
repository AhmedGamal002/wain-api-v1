<?php

namespace App\Jobs;

use App\Models\ReviewPhoto;
use App\Support\PlacePhotoProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * بيعمل نسخ WebP لصورة تقييم مرفوعة (F8): `processing` ← `ready`، أو `failed` بعد المحاولات. نفس
 * `PlacePhotoProcessor` بتاع صور الأماكن (GD، من غير EXIF).
 */
class ProcessReviewPhoto implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(public readonly int $photoId) {}

    public function handle(PlacePhotoProcessor $processor): void
    {
        $photo = ReviewPhoto::query()->find($this->photoId);

        // اتمسحت (التقييم اتحذف أو الـ cleanup) قبل ما الـ job يشتغل
        if ($photo === null) {
            return;
        }

        $result = $processor->process($photo);

        $photo->forceFill([
            'variants' => $result['variants'],
            'width' => min($result['width'], 65535),
            'height' => min($result['height'], 65535),
            'status' => ReviewPhoto::STATUS_READY,
        ])->save();
    }

    public function failed(Throwable $exception): void
    {
        ReviewPhoto::query()->whereKey($this->photoId)->update(['status' => ReviewPhoto::STATUS_FAILED]);

        report($exception);
    }
}
