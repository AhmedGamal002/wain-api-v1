<?php

namespace App\Events;

use App\Models\Review;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * الفريق وافق على تقييم (A6) أو على تعديل تقييم منشور (`$isEdit`). F11 (الإشعارات، أسبوع 18) بيشترك فيه من غير
 * ما يلمس كود الطابور: «تقييمك اتنشر». المتوسط بيتعاد حسابه في `RecalculatePlaceRating`، مش هنا.
 */
class ReviewPublished
{
    use Dispatchable;

    public function __construct(public readonly Review $review, public readonly bool $isEdit = false) {}
}
