<?php

namespace App\Support;

use App\Models\Area;

/**
 * اقتراح المنطقة من الـ pin (03 القسم 4.3 تاب «الموقع»): أقرب منتصف منطقة للنقطة، بشرط ما يبقاش بعيد.
 * بنحسب بالمسافة في PHP على منتصفات المناطق (عشرات بس)، مش على أعمدة الأماكن الـ spatial.
 *
 * بيشمل المناطق غير الفعّالة لإن الفريق بيدخّل أماكن فيها قبل ما تتفعّل (مثلاً 50 مكان).
 */
final class AreaSuggestion
{
    /** أبعد مسافة (كم) بين الـ pin ومنتصف منطقة عشان تتقترح. بعدها الـ pin على الأغلب برّه أي منطقة معرّفة. */
    public const MAX_KM = 25.0;

    private const EARTH_RADIUS_KM = 6371.0088;

    public static function nearest(float $lat, float $lng): ?Area
    {
        $best = null;
        $bestKm = self::MAX_KM;

        foreach (Area::query()->get(['id', 'city_id', 'name_ar', 'center_lat', 'center_lng']) as $area) {
            $km = self::distanceKm($lat, $lng, (float) $area->center_lat, (float) $area->center_lng);

            if ($km <= $bestKm) {
                $best = $area;
                $bestKm = $km;
            }
        }

        return $best;
    }

    /** المسافة بالكيلومتر بين نقطتين (haversine). */
    public static function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return self::EARTH_RADIUS_KM * 2 * asin(min(1.0, sqrt($a)));
    }
}
