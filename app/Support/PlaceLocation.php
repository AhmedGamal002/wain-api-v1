<?php

namespace App\Support;

use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * المكان الوحيد اللي بيكتب أو بيقرا نقطة الموقع (`places.location`).
 *
 * MariaDB: النقطة بتتكتب `POINT(lng lat)` (خط الطول ثم خط العرض) من غير SRID ومن غير axis-order،
 * و`ST_Distance_Sphere` بتتوقع نفس الترتيب. قيم مصر (طول ~31 وعرض ~30) قريبة من بعض، فلو
 * اتبدّلوا هتطلع مسافات غلط من غير أي error. تست `PlaceLocationTest` هو اللي بيحسم الترتيب.
 */
final class PlaceLocation
{
    /**
     * نص SQL لنقطة: `ST_GeomFromText('POINT(lng lat)')`.
     */
    public static function pointSql(float $lat, float $lng): string
    {
        self::assertValid($lat, $lng);

        return sprintf("ST_GeomFromText('POINT(%s %s)')", self::format($lng), self::format($lat));
    }

    /**
     * للاستخدام في insert/update: `'location' => PlaceLocation::point($lat, $lng)`.
     */
    public static function point(float $lat, float $lng): Expression
    {
        return DB::raw(self::pointSql($lat, $lng));
    }

    /**
     * المسافة بالمتر بين عمود `location` ونقطة: `ST_Distance_Sphere(location, POINT(lng lat))`.
     */
    public static function distanceMetersSql(float $lat, float $lng, string $column = 'location'): string
    {
        $wrapped = DB::connection()->getQueryGrammar()->wrap($column);

        return sprintf('ST_Distance_Sphere(%s, %s)', $wrapped, self::pointSql($lat, $lng));
    }

    /**
     * شرط مستطيل حوالين نقطة بنصف قطر بالكيلومتر: `MBRContains(POLYGON, location)`.
     *
     * ده اللي بيخلّي الـ SPATIAL INDEX يتستخدم (EXPLAIN في README)، وبعده بنحسب المسافة الدقيقة
     * بـ distanceMetersSql على النتيجة الصغيرة بس. المستطيل أكبر من الدايرة، فالدقة بتيجي من الفلتر التاني.
     */
    public static function boundingBoxSql(float $lat, float $lng, float $radiusKm, string $column = 'location'): string
    {
        self::assertValid($lat, $lng);

        $wrapped = DB::connection()->getQueryGrammar()->wrap($column);
        $latDelta = $radiusKm / 110.574;
        // عند القطبين cos بيقرّب من صفر، فبنحدّه عشان المستطيل ما يبقاش لانهائي
        $lngDelta = $radiusKm / (111.320 * max(cos(deg2rad($lat)), 0.01));

        $minLat = max($lat - $latDelta, -90.0);
        $maxLat = min($lat + $latDelta, 90.0);
        $minLng = max($lng - $lngDelta, -180.0);
        $maxLng = min($lng + $lngDelta, 180.0);

        $ring = implode(', ', [
            self::format($minLng).' '.self::format($minLat),
            self::format($maxLng).' '.self::format($minLat),
            self::format($maxLng).' '.self::format($maxLat),
            self::format($minLng).' '.self::format($maxLat),
            self::format($minLng).' '.self::format($minLat),
        ]);

        return sprintf("MBRContains(ST_GeomFromText('POLYGON((%s))'), %s)", $ring, $wrapped);
    }

    private static function assertValid(float $lat, float $lng): void
    {
        if (! is_finite($lat) || ! is_finite($lng) || abs($lat) > 90 || abs($lng) > 180) {
            throw new InvalidArgumentException("Invalid coordinates: lat={$lat}, lng={$lng}");
        }
    }

    /**
     * رقم عشري بنقطة ثابتة، مستقل عن الـ locale.
     */
    private static function format(float $value): string
    {
        return number_format($value, 7, '.', '');
    }
}
