<?php

namespace App\Support;

use App\Events\PlaceChanged;
use App\Models\Place;
use App\Models\Review;
use Illuminate\Support\Facades\DB;

/**
 * متوسط التقييم وعدّاده للمكان (06 القسم 2.3): `rating_avg` و`rating_count` بيتحسبوا من `status = published`
 * بالقيم المنشورة بس، فالتقييم المرفوض أو قيد المراجعة أو المخفي، وتعديل لسه ما اتوافقش عليه، ما بيغيّروش
 * المتوسط.
 *
 * بيتنادى من `RecalculatePlaceRating` (job بعد كل تغيير حالة). بعد الحفظ بيتطلق `PlaceChanged` عشان cache القوايم
 * والتفاصيل (ترتيب `rating` وكارت المكان) يشوفوا الرقم الجديد.
 */
final class PlaceRating
{
    public static function recalculate(int $placeId): void
    {
        $place = Place::query()->withTrashed()->find($placeId);

        if ($place === null) {
            return;
        }

        $stats = Review::query()
            ->where('place_id', $placeId)
            ->published()
            ->selectRaw('COUNT(*) as total, AVG(rating) as average')
            ->first();

        $count = (int) ($stats->total ?? 0);
        $average = $count > 0 ? round((float) $stats->average, 2) : 0;

        // من غير model events (الـ Observer ما لهوش لازمة هنا): الحقلين محسوبين ومش في سجل التغييرات
        DB::table('places')->where('id', $placeId)->update([
            'rating_avg' => $average,
            'rating_count' => $count,
        ]);

        PlaceChanged::dispatch($place->refresh());
    }

    /**
     * عدد التقييمات المنشورة لكل نجمة (1 لـ 5)، للتوزيع في صفحة المكان.
     *
     * @return array<int, int>
     */
    public static function distribution(int $placeId): array
    {
        $rows = Review::query()
            ->where('place_id', $placeId)
            ->published()
            ->selectRaw('rating, COUNT(*) as total')
            ->groupBy('rating')
            ->pluck('total', 'rating');

        $distribution = [];

        foreach (range(5, 1) as $star) {
            $distribution[$star] = (int) ($rows[$star] ?? 0);
        }

        return $distribution;
    }
}
