<?php

namespace App\Models;

use App\Models\Concerns\HasPhotoVariants;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * صورة المكان (gallery أو menu). الأصل `path` ما بيطلعش في الـ API أبداً (03 القسم 5.3)،
 * بيطلع بس الـ variants الجاهزة.
 */
class PlacePhoto extends Model
{
    use HasPhotoVariants;

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
