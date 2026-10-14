<?php

namespace App\Support\Search;

use Illuminate\Support\Facades\DB;

/**
 * عمود `places.search_text` (02 F4، 06 القسم 5): النص المطبَّع اللي البحث بيدوّر فيه، بـ 3 أجزاء بفاصل `|`
 * (والفاصل مش ممكن يطلع في الناتج، `SearchNormalizer` بيشيله):
 *
 *     الاسمين | الـ aliases | الباقي (التصنيفات والخصائص والمنطقة وأسمائها التانية والوصف)
 *
 * التقسيم ده هو اللي بيخلّي الترتيب «تطابق الاسم ثم الـ alias ثم الباقي» يتحسب في SQL من غير ما نحمّل
 * الأماكن كلها (`DatabasePlaceSearch`). بيتبني من `BuildPlaceSearchText` مع كل تغيير في المكان، ومن
 * `CatalogSearchRefresh` لما اسم تصنيف أو خاصية أو منطقة يتغيّر، ومن `places:rebuild-search` (كل ليلة وللأماكن
 * اللي اتكتبت بـ DB::table أو import).
 *
 * بيكتب بـ UPDATE واحد للمجموعة من غير model events (`updated_at` والسجل ما بيتأثروش، والعمود مشتق).
 */
final class PlaceSearchText
{
    public const SEPARATOR = '|';

    /** حد جزء «الباقي» بالحروف: الوصف الطويل ما يكبّرش العمود ولا البحث. */
    private const MAX_REST_LENGTH = 6000;

    /**
     * @param  list<int>  $placeIds
     * @return int عدد الأماكن اللي اتبنى نصها
     */
    public static function rebuild(array $placeIds): int
    {
        $placeIds = array_values(array_unique(array_map('intval', $placeIds)));

        if ($placeIds === []) {
            return 0;
        }

        $places = DB::table('places')
            ->whereIn('id', $placeIds)
            ->get(['id', 'name_ar', 'name_en', 'search_aliases', 'description_ar', 'description_en', 'primary_category_id', 'area_id']);

        if ($places->isEmpty()) {
            return 0;
        }

        $placeIds = $places->pluck('id')->all();

        $categories = DB::table('categories')->get(['id', 'name_ar', 'name_en'])->keyBy('id');
        // الخاصية المخفية (is_active = false) ما بتظهرش للمستخدم، فما تطابقش في البحث
        $attributes = DB::table('attributes')->where('is_active', true)->get(['id', 'name_ar', 'name_en'])->keyBy('id');
        $areas = DB::table('areas')
            ->whereIn('id', $places->pluck('area_id')->unique()->all())
            ->get(['id', 'name_ar', 'name_en', 'aliases'])
            ->keyBy('id');

        $categoryIds = DB::table('place_category')->whereIn('place_id', $placeIds)->get(['place_id', 'category_id'])
            ->groupBy('place_id');
        $attributeIds = DB::table('attribute_place')->whereIn('place_id', $placeIds)->get(['place_id', 'attribute_id'])
            ->groupBy('place_id');

        $cases = [];
        $bindings = [];

        foreach ($places as $place) {
            $rest = [$place->description_ar, $place->description_en];

            // التصنيف الرئيسي من المكان نفسه (مش من الـ pivot): الـ pivot بيتحفظ بعد أول حفظ، والرئيسي دايماً ضمنه
            $ownCategories = $categoryIds->get($place->id, collect())->pluck('category_id')->prepend($place->primary_category_id)->unique();

            foreach ($ownCategories as $categoryId) {
                $category = $categories->get($categoryId);
                array_push($rest, $category?->name_ar, $category?->name_en);
            }

            foreach ($attributeIds->get($place->id, []) as $row) {
                $attribute = $attributes->get($row->attribute_id);
                array_push($rest, $attribute?->name_ar, $attribute?->name_en);
            }

            $area = $areas->get($place->area_id);
            if ($area !== null) {
                array_push($rest, $area->name_ar, $area->name_en, ...self::list($area->aliases));
            }

            $text = implode(self::SEPARATOR, [
                self::segment([$place->name_ar, $place->name_en]),
                self::segment(self::list($place->search_aliases)),
                mb_substr(self::segment($rest), 0, self::MAX_REST_LENGTH),
            ]);

            $cases[] = 'WHEN ? THEN ?';
            array_push($bindings, $place->id, $text);
        }

        $placeholders = implode(',', array_fill(0, count($placeIds), '?'));

        DB::update(
            'UPDATE places SET search_text = CASE id '.implode(' ', $cases).' END WHERE id IN ('.$placeholders.')',
            [...$bindings, ...$placeIds],
        );

        // عدد الأماكن اللي اتبنى نصها (مش «الصفوف اللي اتغيّرت»: MariaDB بيعدّ التغيير الفعلي بس)
        return $places->count();
    }

    /**
     * كل الأماكن (بما فيهم المحذوف، لو اتسترجع يلاقي نصه جاهز) على دفعات.
     */
    public static function rebuildAll(int $chunk = 200): int
    {
        $total = 0;

        DB::table('places')->orderBy('id')->select('id')->chunkById($chunk, function ($rows) use (&$total): void {
            $total += self::rebuild($rows->pluck('id')->all());
        });

        return $total;
    }

    /**
     * @param  array<int, mixed>  $values
     */
    private static function segment(array $values): string
    {
        $words = [];

        foreach ($values as $value) {
            $normalized = SearchNormalizer::normalize(is_string($value) ? $value : null);

            if ($normalized !== '') {
                $words[] = $normalized;
            }
        }

        return implode(' ', $words);
    }

    /**
     * قايمة نصوص من عمود JSON (بيرجع من `DB::table` نص).
     *
     * @return list<string>
     */
    private static function list(mixed $json): array
    {
        $decoded = is_string($json) ? json_decode($json, true) : $json;

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }
}
