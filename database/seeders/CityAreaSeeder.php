<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * المدينة الأولى (القاهرة) والمنطقة الأولى (التجمع الخامس)، فعّالتين.
 * ده اللي بيخلّي أي seeder أماكن تجريبية وأي API يشتغل من الأسبوع 2. باقي المناطق في أسبوع 3 (A4).
 *
 * center_lat / center_lng تقريبيين، وبيتعدّلوا من A4 لما يتبني.
 */
class CityAreaSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('cities')->insertOrIgnore([
            'slug' => 'cairo',
            'name_ar' => 'القاهرة',
            'name_en' => 'Cairo',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $cityId = DB::table('cities')->where('slug', 'cairo')->value('id');

        DB::table('areas')->insertOrIgnore([
            'city_id' => $cityId,
            'slug' => 'new-cairo',
            'name_ar' => 'التجمع الخامس',
            'name_en' => 'New Cairo',
            'aliases' => json_encode(
                ['التجمع', 'التجمع الخامس', 'القاهرة الجديدة', 'New Cairo', '5th settlement', 'Fifth Settlement'],
                JSON_UNESCAPED_UNICODE,
            ),
            'center_lat' => 30.0300000,
            'center_lng' => 31.4700000,
            'is_active' => true,
            'sort_order' => 1,
        ]);
    }
}
