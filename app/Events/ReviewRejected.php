<?php

namespace App\Events;

use App\Models\Review;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * الفريق رفض تقييم (A6) أو رفض تعديل على تقييم منشور (`$isEdit`، والمنشور القديم بيفضل ظاهر). `$reason`
 * نص السبب اللي صاحب التقييم بيشوفه. F11 بيشترك فيه («تقييمك اترفض»).
 */
class ReviewRejected
{
    use Dispatchable;

    public function __construct(
        public readonly Review $review,
        public readonly string $reason,
        public readonly bool $isEdit = false,
    ) {}
}
