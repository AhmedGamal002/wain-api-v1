<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * بيبني الجدول المشتق `place_hours` من `opening_hours` و`ramadan_hours` (03 القسم 7.1)، عشان فلتر
 * «مفتوح دلوقتي» يبقى `WHERE EXISTS` بسيط على الدقيقة بدل فلترة على JSON.
 */
final class PlaceHoursBuilder
{
    /**
     * بيستبدل صفوف المكان كلها. آمن يتنادى أكتر من مرة.
     */
    public static function rebuild(int $placeId): int
    {
        $place = DB::table('places')->where('id', $placeId)->first(['opening_hours', 'ramadan_hours']);

        return DB::transaction(function () use ($placeId, $place): int {
            DB::table('place_hours')->where('place_id', $placeId)->delete();

            if ($place === null) {
                return 0;
            }

            $rows = [
                ...self::rows($placeId, $place->opening_hours, false),
                ...self::rows($placeId, $place->ramadan_hours, true),
            ];

            if ($rows !== []) {
                DB::table('place_hours')->insert($rows);
            }

            return count($rows);
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function rows(int $placeId, ?string $json, bool $ramadan): array
    {
        $hours = $json === null ? null : json_decode($json, true);
        if (! is_array($hours)) {
            return [];
        }

        return array_map(fn (array $interval) => [
            'place_id' => $placeId,
            'is_ramadan' => $ramadan,
            'opens_minute' => $interval[0],
            'closes_minute' => $interval[1],
        ], OpeningHours::intervals($hours));
    }
}
