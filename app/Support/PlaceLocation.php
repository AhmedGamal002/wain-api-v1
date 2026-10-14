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
    // حدود مصر التقريبية: بتمسك أي إحداثية برّه مصر (رقم ناقص أو زيادة). ماتمسكش الطول والعرض المبدّلين،
    // لإن مدى الاتنين متداخل (25 لـ 32) جوه مصر، فده بتراجعه العين على الخريطة (map picker في A1).
    public const EGYPT_LAT_MIN = 22;

    public const EGYPT_LAT_MAX = 32;

    public const EGYPT_LNG_MIN = 24;

    public const EGYPT_LNG_MAX = 37;

    /**
     * الإحداثية جوه حدود مصر التقريبية؟ شرط «المنطقة والموقع» في شروط النشر (03 القسم 4.3).
     */
    public static function isInsideEgypt(float $lat, float $lng): bool
    {
        return $lat >= self::EGYPT_LAT_MIN && $lat <= self::EGYPT_LAT_MAX
            && $lng >= self::EGYPT_LNG_MIN && $lng <= self::EGYPT_LNG_MAX;
    }

    /**
     * المسافة بالمتر بين نقطتين في PHP (haversine) من غير الداتابيز: لمقارنة سطور ملف الـ CSV ببعضها وبمركز المنطقة.
     * الأماكن المحفوظة بتتقاس بـ `distanceMetersSql` (نفس الكرة، فالفرق أقل من متر على مسافات المدينة).
     */
    public static function haversineMeters(float $latA, float $lngA, float $latB, float $lngB): float
    {
        $dLat = deg2rad($latB - $latA);
        $dLng = deg2rad($lngB - $lngA);

        $a = sin($dLat / 2) ** 2 + cos(deg2rad($latA)) * cos(deg2rad($latB)) * sin($dLng / 2) ** 2;

        return 2 * 6371000.0 * asin(min(1.0, sqrt($a)));
    }

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

        $latDelta = $radiusKm / 110.574;
        // عند القطبين cos بيقرّب من صفر، فبنحدّه عشان المستطيل ما يبقاش لانهائي
        $lngDelta = $radiusKm / (111.320 * max(cos(deg2rad($lat)), 0.01));

        return self::rectangleSql(
            max($lat - $latDelta, -90.0),
            max($lng - $lngDelta, -180.0),
            min($lat + $latDelta, 90.0),
            min($lng + $lngDelta, 180.0),
            $column,
        );
    }

    /**
     * شرط مستطيل بزوايا (الأدنى والأقصى للعرض والطول): `MBRContains(POLYGON, location)`. نفس الـ SPATIAL INDEX
     * بتاع `boundingBoxSql`، ومنه بتتبني حدود الخريطة (`GET /places/map`).
     */
    public static function rectangleSql(float $minLat, float $minLng, float $maxLat, float $maxLng, string $column = 'location'): string
    {
        self::assertValid($minLat, $minLng);
        self::assertValid($maxLat, $maxLng);

        $wrapped = DB::connection()->getQueryGrammar()->wrap($column);

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
