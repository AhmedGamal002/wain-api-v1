<?php

namespace App\Models;

use App\Models\Concerns\HasPhotoVariants;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * صورة تقييم (F8). بتتعمل وقت الرفع (`POST /uploads/photos`) من غير تقييم (`review_id` null)، وبتتربط لما
 * التقييم يتبعت. الأصل `path` على disk خاص ما بيطلعش في الـ API، والنسخ (thumb وmedium وlarge) من
 * `PlacePhotoProcessor` زي صور الأماكن.
 */
class ReviewPhoto extends Model
{
    use HasPhotoVariants;

    public const UPDATED_AT = null;

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'variants' => 'array',
            'sort_order' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Review, $this> */
    public function review(): BelongsTo
    {
        return $this->belongsTo(Review::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }
}
