<?php

namespace Tests\Feature;

use App\Support\PlaceLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * يثبّت ترتيب المحاور على MariaDB (POINT(lng lat)) قبل أي seeder أو إدخال أماكن.
 *
 * القاهرة–الإسكندرية لوحدها ما بتكشفش التبديل: الصح 180.0 كم والمبدّل 183.5 كم.
 * عشان كده فيه زوج القاهرة–أسوان (682 كم صح، 591 كم مبدّل) وقراءة ST_X / ST_Y مباشرة.
 */
class PlaceLocationTest extends TestCase
{
    use RefreshDatabase;

    private const CAIRO = [30.0444, 31.2357];       // [lat, lng]

    private const ALEXANDRIA = [31.2001, 29.9187];

    private const ASWAN = [24.0889, 32.8998];

    public function test_point_is_stored_as_lng_then_lat(): void
    {
        $id = $this->createPlace('cairo', self::CAIRO);

        $row = DB::selectOne('SELECT ST_X(location) AS x, ST_Y(location) AS y FROM places WHERE id = ?', [$id]);

        $this->assertEqualsWithDelta(31.2357, (float) $row->x, 0.00001, 'ST_X لازم يكون خط الطول');
        $this->assertEqualsWithDelta(30.0444, (float) $row->y, 0.00001, 'ST_Y لازم يكون خط العرض');
    }

    public function test_cairo_to_alexandria_is_about_180_km(): void
    {
        $this->createPlace('cairo', self::CAIRO);

        $km = $this->kmFromStoredPlaceTo('cairo', self::ALEXANDRIA);

        // المبدّل 183.5، فالـ tolerance ضيق بقصد
        $this->assertEqualsWithDelta(180.0, $km, 1.5);
    }

    public function test_cairo_to_aswan_is_about_682_km(): void
    {
        $this->createPlace('cairo', self::CAIRO);

        $km = $this->kmFromStoredPlaceTo('cairo', self::ASWAN);

        // المبدّل 590.8
        $this->assertEqualsWithDelta(682.4, $km, 3.0);
    }

    public function test_distance_between_two_stored_places_matches_query_time_point(): void
    {
        $cairo = $this->createPlace('cairo', self::CAIRO);
        $alex = $this->createPlace('alexandria', self::ALEXANDRIA);

        $stored = DB::selectOne(
            'SELECT ST_Distance_Sphere(a.location, b.location) / 1000 AS km FROM places a, places b WHERE a.id = ? AND b.id = ?',
            [$cairo, $alex],
        );

        $this->assertEqualsWithDelta(
            $this->kmFromStoredPlaceTo('cairo', self::ALEXANDRIA),
            (float) $stored->km,
            0.001,
        );
    }

    public function test_point_sql_is_locale_independent_and_lng_first(): void
    {
        $this->assertSame(
            "ST_GeomFromText('POINT(31.2357000 30.0444000)')",
            PlaceLocation::pointSql(30.0444, 31.2357),
        );
    }

    public function test_invalid_coordinates_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PlaceLocation::pointSql(91.0, 31.0);
    }

    /**
     * @param  array{0: float, 1: float}  $latLng
     */
    private function createPlace(string $slug, array $latLng): int
    {
        // أقل مراجع مطلوبة للـ FKs (تستخدم نفس الصفوف لو اتعملت قبل كده)
        DB::table('cities')->insertOrIgnore(['id' => 1, 'slug' => 'cairo', 'name_ar' => 'القاهرة', 'name_en' => 'Cairo']);
        DB::table('areas')->insertOrIgnore([
            'id' => 1, 'city_id' => 1, 'slug' => 'new-cairo', 'name_ar' => 'التجمع الخامس',
            'name_en' => 'New Cairo', 'center_lat' => 30.0300000, 'center_lng' => 31.4700000,
        ]);
        DB::table('categories')->insertOrIgnore([
            'id' => 1, 'slug' => 'cafe', 'name_ar' => 'كافيه', 'name_en' => 'Cafe', 'icon' => '☕', 'color' => '#E8593C',
        ]);

        return DB::table('places')->insertGetId([
            'slug' => $slug,
            'name_ar' => $slug,
            'name_en' => $slug,
            'primary_category_id' => 1,
            'city_id' => 1,
            'area_id' => 1,
            'address_ar' => 'عنوان تجريبي',
            'latitude' => $latLng[0],
            'longitude' => $latLng[1],
            'location' => PlaceLocation::point($latLng[0], $latLng[1]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array{0: float, 1: float}  $latLng
     */
    private function kmFromStoredPlaceTo(string $slug, array $latLng): float
    {
        $row = DB::selectOne(
            'SELECT '.PlaceLocation::distanceMetersSql($latLng[0], $latLng[1]).' / 1000 AS km FROM places WHERE slug = ?',
            [$slug],
        );

        return (float) $row->km;
    }
}
