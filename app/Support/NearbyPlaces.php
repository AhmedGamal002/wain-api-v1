<?php

namespace App\Support;

use App\Models\Place;
use Illuminate\Database\Eloquent\Collection;

/**
 * «قريب منك» (03 القسم 7.5): المنشور جوه نصف القطر مرتب بالأقرب، مشترك بين `GET /places/nearby` و`GET /home`.
 *
 * بيفلتر أولاً بمستطيل حوالين النقطة (`MBRContains`، بيستخدم الـ SPATIAL INDEX)، وبعدين بالمسافة الدقيقة. كل
 * كارت بيرجع بـ `distance_m` (الـ resource بيحوّله لـ `distance_km`).
 */
final class NearbyPlaces
{
    /**
     * @return Collection<int, Place>
     */
    public static function find(float $lat, float $lng, float $radiusKm, int $limit): Collection
    {
        $distance = PlaceLocation::distanceMetersSql($lat, $lng);

        return Place::query()
            ->published()
            ->with(['primaryCategory', 'area', 'coverPhoto'])
            ->select('places.*')
            ->selectRaw($distance.' as distance_m')
            ->whereRaw(PlaceLocation::boundingBoxSql($lat, $lng, $radiusKm))
            ->whereRaw($distance.' <= ?', [$radiusKm * 1000])
            ->orderBy('distance_m')
            ->orderBy('places.id')
            ->limit($limit)
            ->get();
    }
}
