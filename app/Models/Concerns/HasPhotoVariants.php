<?php

namespace App\Models\Concerns;

use Illuminate\Support\Facades\Storage;

/**
 * نسخ الصورة (thumb وmedium وlarge) كمسارات نسبية على disk الصور. الأصل `path` ما بيطلعش في الـ API أبداً.
 * مشترك بين صور الأماكن (`PlacePhoto`) وصور التقييمات (`ReviewPhoto`).
 *
 * الموديل لازم يكون عنده `variants` (array) و`status`، وثابت `STATUS_READY`.
 */
trait HasPhotoVariants
{
    /**
     * رابط نسخة بمقاس (thumb أو medium أو large). الرابط المطلق (بيانات تجريبية) بيتمرّر زي ما هو،
     * والمسار النسبي بيتحوّل لرابط من disk الصور (`wain.photos.disk`: public دلوقتي، وR2 بعد أسبوع 9).
     */
    public function variantUrl(string $size): ?string
    {
        $value = $this->variants[$size] ?? null;

        if (! is_string($value) || $value === '') {
            return null;
        }

        if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://')) {
            return $value;
        }

        return Storage::disk(config('wain.photos.disk'))->url($value);
    }

    public function isReady(): bool
    {
        return $this->status === self::STATUS_READY;
    }
}
