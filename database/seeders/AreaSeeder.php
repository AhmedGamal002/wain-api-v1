<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * باقي المناطق (06 القسم 6، أسبوع 3): **غير فعّالة** لحد ما يبقى فيها عدد كافي من الأماكن المنشورة (A4).
 * بتتفعّل من لوحة المناطق. التجمع الخامس والقاهرة في `CityAreaSeeder` (فعّالتين).
 *
 * القائمة اقتراح لأن الوثايق ما حدّدتش غير أمثلة (PLC-03: التجمع والزمالك والشيخ زايد). الـ slugs بتتولّد من
 * الاسم الإنجليزي وما بتتغيرش بعد أول استخدام، فراجع القايمة قبل ما تتفعّل أي منطقة على production.
 * إحداثيات المنتصف **تقريبية** (للخريطة لما المستخدم يرفض الموقع)، وبتتظبط من اللوحة. insertOrIgnore عشان
 * إعادة التشغيل ما تمسحش تعديلات الأدمن. بيشتغل على production.
 */
class AreaSeeder extends Seeder
{
    public function run(): void
    {
        // القاهرة والتجمع الخامس لازم يكونوا موجودين (الترتيب والـ FK)
        $this->call(CityAreaSeeder::class);

        DB::table('cities')->insertOrIgnore([
            'slug' => 'giza',
            'name_ar' => 'الجيزة',
            'name_en' => 'Giza',
            'is_active' => true,
            'sort_order' => 2,
        ]);

        $cities = DB::table('cities')->pluck('id', 'slug');

        // [المدينة، slug، الاسم عربي، الاسم إنجليزي، أسماء تانية، lat، lng]
        $areas = [
            ['cairo', 'zamalek', 'الزمالك', 'Zamalek', ['الزمالك', 'Zamalek'], 30.0626, 31.2197],
            ['cairo', 'maadi', 'المعادي', 'Maadi', ['المعادي', 'Maadi'], 29.9602, 31.2569],
            ['cairo', 'heliopolis', 'مصر الجديدة', 'Heliopolis', ['مصر الجديدة', 'هليوبوليس', 'Heliopolis', 'Masr El Gedida'], 30.0915, 31.3235],
            ['cairo', 'nasr-city', 'مدينة نصر', 'Nasr City', ['مدينة نصر', 'Nasr City', 'Madinet Nasr'], 30.0600, 31.3400],
            ['cairo', 'downtown', 'وسط البلد', 'Downtown', ['وسط البلد', 'Downtown', 'Downtown Cairo'], 30.0444, 31.2357],
            ['cairo', 'al-rehab', 'الرحاب', 'Al Rehab', ['الرحاب', 'Rehab', 'Al Rehab City'], 30.0600, 31.4900],
            ['cairo', 'madinaty', 'مدينتي', 'Madinaty', ['مدينتي', 'Madinaty'], 30.1050, 31.6350],
            ['cairo', 'el-shorouk', 'الشروق', 'El Shorouk', ['الشروق', 'Shorouk', 'El Shorouk City'], 30.1214, 31.6029],
            ['giza', 'dokki', 'الدقي', 'Dokki', ['الدقي', 'Dokki', 'Doqqi'], 30.0386, 31.2123],
            ['giza', 'mohandessin', 'المهندسين', 'Mohandessin', ['المهندسين', 'Mohandessin', 'Mohandiseen'], 30.0560, 31.2000],
            ['giza', 'haram', 'الهرم', 'Haram', ['الهرم', 'Haram', 'Pyramids', 'Giza Pyramids'], 29.9870, 31.1613],
            ['giza', 'sheikh-zayed', 'الشيخ زايد', 'Sheikh Zayed', ['الشيخ زايد', 'Sheikh Zayed', 'Zayed'], 30.0278, 30.9776],
            ['giza', '6th-of-october', '6 أكتوبر', '6th of October', ['6 أكتوبر', 'السادس من أكتوبر', 'October', '6th of October'], 29.9285, 30.9188],
        ];

        // بعد التجمع الخامس (ترتيبه 1)
        $order = 1;

        foreach ($areas as [$city, $slug, $nameAr, $nameEn, $aliases, $lat, $lng]) {
            DB::table('areas')->insertOrIgnore([
                'city_id' => $cities[$city],
                'slug' => $slug,
                'name_ar' => $nameAr,
                'name_en' => $nameEn,
                'aliases' => json_encode($aliases, JSON_UNESCAPED_UNICODE),
                'center_lat' => $lat,
                'center_lng' => $lng,
                'is_active' => false,
                'sort_order' => ++$order,
            ]);
        }
    }
}
