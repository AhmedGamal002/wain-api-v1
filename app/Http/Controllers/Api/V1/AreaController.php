<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Area;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/**
 * @group Areas
 */
class AreaController extends Controller
{
    /**
     * List areas
     *
     * المناطق المتاحة للمستخدمين (المنطقة والمدينة الفعّالتين) بترتيب المدينة ثم المنطقة، ومعاها عدد الأماكن
     * المنشورة. شاشة اختيار المنطقة (S04 وS21) بتستخدمه، والتطبيق بيحسب أقرب منطقة من `center`.
     * الـ `slug` هو قيمة بارامتر `area` في `GET /places`.
     *
     * بيتخزن في cache ساعة وبيتمسح مع أي تعديل في المناطق أو المدن. عدد الأماكن ممكن يتأخر لحد ساعة.
     *
     * @response 200 {"data": [{"slug": "new-cairo", "name": "التجمع الخامس", "city": {"slug": "cairo", "name": "القاهرة"}, "center": {"lat": 30.03, "lng": 31.47}, "places_count": 26}]}
     */
    public function index(): JsonResponse
    {
        // arrays مش Eloquent models (`cache.serializable_classes = false`)، واللغة بتتطبّق بعد الـ cache.
        $rows = Cache::remember(
            Area::CACHE_KEY,
            now()->addHour(),
            fn () => Area::query()
                ->join('cities', 'cities.id', '=', 'areas.city_id')
                ->where('areas.is_active', true)
                ->where('cities.is_active', true)
                ->select([
                    'areas.slug', 'areas.name_ar', 'areas.name_en', 'areas.center_lat', 'areas.center_lng',
                    'cities.slug as city_slug', 'cities.name_ar as city_name_ar', 'cities.name_en as city_name_en',
                ])
                ->selectSub(
                    fn ($places) => $places->from('places')
                        ->selectRaw('count(*)')
                        ->whereColumn('places.area_id', 'areas.id')
                        ->where('places.status', 'active')
                        ->whereNull('places.deleted_at'),
                    'places_count',
                )
                ->orderBy('cities.sort_order')
                ->orderBy('areas.sort_order')
                ->orderBy('areas.id')
                ->get()
                ->map(fn (Area $area) => $area->getAttributes())
                ->all(),
        );

        $english = app()->getLocale() === 'en';
        $localized = fn (array $row, string $field) => $english && filled($row[$field.'_en'] ?? null)
            ? $row[$field.'_en']
            : $row[$field.'_ar'];

        return response()->json([
            'data' => array_map(fn (array $row) => [
                'slug' => $row['slug'],
                'name' => $localized($row, 'name'),
                'city' => [
                    'slug' => $row['city_slug'],
                    'name' => $localized(['name_ar' => $row['city_name_ar'], 'name_en' => $row['city_name_en']], 'name'),
                ],
                'center' => ['lat' => (float) $row['center_lat'], 'lng' => (float) $row['center_lng']],
                'places_count' => (int) $row['places_count'],
            ], $rows),
        ]);
    }
}
