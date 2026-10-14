<?php

namespace App\Support;

use App\Models\Place;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * تصدير الأماكن CSV (A10، 02): بنفس أعمدة القالب (`PlaceCsv::columns`) ومعاها أعمدة مرجعية في الآخر (`id` و`slug`
 * و`status`) بيتجاهلها الاستيراد، فالملف نفسه بيترفع تاني كأماكن جديدة. بيستخدمه التصدير من القايمة (بنفس
 * الفلاتر والبحث والترتيب) والإجراء الجماعي على المحدد.
 *
 * الترتيب هو ترتيب الاستعلام اللي اتبعت (الجدول)، والتحميل على دفعات 200 عشان قايمة كبيرة ما تحمّلش الذاكرة.
 */
final class PlaceCsvExporter
{
    private const CHUNK = 200;

    /** أعمدة نص حر: أي خلية بتبدأ بـ `= + - @` بتتحمي من تنفيذها كمعادلة في Excel (`PlaceCsv::safeCell`). */
    private const FREE_TEXT = [
        'name_ar', 'name_en', 'search_aliases', 'description_ar', 'description_en', 'address_ar', 'address_en',
        'google_maps_url', 'facebook_url', 'website', 'menu_url', 'instagram', 'internal_notes',
    ];

    /**
     * @param  Builder<Place>  $query  الاستعلام بفلاتره وترتيبه (من الجدول) أو المحدد
     */
    public static function download(Builder $query, string $fileName): StreamedResponse
    {
        // ترتيب الاستعلام محفوظ: بنسحب الـ ids بالترتيب الأول وبعدها نحمّل الأماكن على دفعات
        $ids = (clone $query)->pluck('places.id')->map(fn ($id) => (int) $id)->all();

        return response()->streamDownload(function () use ($ids): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [...PlaceCsv::columns(), ...PlaceCsv::REFERENCE_COLUMNS], ',', '"', '\\');

            foreach (array_chunk($ids, self::CHUNK) as $chunk) {
                $places = Place::query()
                    ->withTrashed()
                    ->with([
                        'categories:categories.id,slug',
                        'placeAttributes:attributes.id,slug',
                        'area:id,slug',
                        'city:id,slug',
                        'primaryCategory:id,slug',
                    ])
                    ->whereIn('places.id', $chunk)
                    ->get()
                    ->keyBy('id');

                foreach ($chunk as $id) {
                    if ($place = $places->get($id)) {
                        fputcsv($out, self::row($place), ',', '"', '\\');
                    }
                }
            }

            fclose($out);
        }, $fileName, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return list<string>
     */
    public static function row(Place $place): array
    {
        $primary = $place->primaryCategory?->slug;
        $categories = $place->categories->pluck('slug')->all();

        // التصنيف الرئيسي الأول: الاستيراد بياخد الأول رئيسي لو العمود الرئيسي فاضي
        if ($primary !== null) {
            $categories = [$primary, ...array_values(array_diff($categories, [$primary]))];
        }

        $values = [
            'name_ar' => $place->name_ar,
            'name_en' => $place->name_en,
            'search_aliases' => implode(PlaceCsv::LIST_SEPARATOR, (array) ($place->search_aliases ?? [])),
            'description_ar' => $place->description_ar,
            'description_en' => $place->description_en,
            'categories' => implode(PlaceCsv::LIST_SEPARATOR, $categories),
            'primary_category' => $primary,
            'attributes' => $place->placeAttributes->pluck('slug')->implode(PlaceCsv::LIST_SEPARATOR),
            'price_min' => $place->price_min,
            'price_max' => $place->price_max,
            'city' => $place->city?->slug,
            'area' => $place->area?->slug,
            'address_ar' => $place->address_ar,
            'address_en' => $place->address_en,
            'latitude' => $place->latitude,
            'longitude' => $place->longitude,
            'google_maps_url' => $place->google_maps_url,
            'phone' => $place->phone,
            'whatsapp' => $place->whatsapp,
            'instagram' => $place->instagram,
            'facebook_url' => $place->facebook_url,
            'website' => $place->website,
            'menu_url' => $place->menu_url,
            'internal_notes' => $place->internal_notes,
        ];

        foreach (OpeningHours::DAYS as $day) {
            $values['hours_'.$day] = PlaceCsvHours::format($place->opening_hours[$day] ?? null);
            $values['ramadan_hours_'.$day] = PlaceCsvHours::format($place->ramadan_hours[$day] ?? null);
        }

        $values += ['id' => $place->id, 'slug' => $place->slug, 'status' => $place->status];

        $row = [];

        foreach ([...PlaceCsv::columns(), ...PlaceCsv::REFERENCE_COLUMNS] as $column) {
            $value = (string) ($values[$column] ?? '');

            $row[] = in_array($column, self::FREE_TEXT, true) ? PlaceCsv::safeCell($value) : $value;
        }

        return $row;
    }

    /**
     * عدد الأماكن اللي هتتصدّر (لرسالة التأكيد).
     *
     * @param  Builder<Place>  $query
     */
    public static function count(Builder $query): int
    {
        return (clone $query)->count(DB::raw('places.id'));
    }
}
