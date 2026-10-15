<?php

namespace App\Jobs;

use App\Support\PlaceRating;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * بيعيد حساب `rating_avg` و`rating_count` للمكان بعد أي تغيير حالة تقييم (06 القسم 5 «بعد حدث»). بيحسب من
 * الداتابيز وقت التشغيل مش من قيمة اتبعتت، فتشغيله مرتين أو متأخر مش بيغلط.
 */
class RecalculatePlaceRating implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $placeId) {}

    public function handle(): void
    {
        PlaceRating::recalculate($this->placeId);
    }
}
