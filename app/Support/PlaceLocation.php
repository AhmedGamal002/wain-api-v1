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
