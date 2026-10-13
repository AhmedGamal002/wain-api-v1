<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * صورة المكان (gallery أو menu). الأصل `path` ما بيطلعش في الـ API أبداً (03 القسم 5.3)،
 * بيطلع بس الـ variants الجاهزة.
 */
class PlacePhoto extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'variants' => 'array',
            'sort_order' => 'integer',
        ];
    }

    /**
     * رابط نسخة بمقاس (thumb أو medium أو large). الرابط المطلق (بيانات تجريبية) بيتمرّر زي ما هو،
     * والمسار النسبي (R2 بعد أسبوع 9) بيتحوّل لرابط من الـ disk.
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

        return Storage::url($value);
    }

    public function isReady(): bool
    {
        return $this->status === 'ready';
    }

    /**
     * الوصف بلغة الطلب (الإنجليزي الفاضي بيرجع العربي)، أو null لو مفيش.
     */
    public function localizedCaption(): ?string
    {
        if (app()->getLocale() === 'en' && filled($this->caption_en)) {
            return $this->caption_en;
        }

        return filled($this->caption_ar) ? $this->caption_ar : null;
    }
}
