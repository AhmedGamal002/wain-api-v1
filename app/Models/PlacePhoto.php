<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    public const TYPE_GALLERY = 'gallery';

    public const TYPE_MENU = 'menu';

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

    /** @return BelongsTo<Place, $this> */
    public function place(): BelongsTo
    {
        return $this->belongsTo(Place::class);
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
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
