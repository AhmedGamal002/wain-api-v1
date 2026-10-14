<?php

namespace App\Support\Search;

use App\Models\Area;
use App\Models\Attribute;
use App\Models\Category;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * اسم تصنيف أو خاصية أو منطقة اتغيّر ← `search_text` للأماكن اللي تحته بيتحدّث في نفس الحفظ (02 F4).
 *
 * المشكلة إن الأماكن دي ما اتحفظتش، فمفيش `PlaceChanged` يبنيهم. الحل إن حفظ عنصر المرجعية نفسه هو اللي
 * بيعيد بناء أماكنه: الأماكن المتأثرة بس، على دفعات (UPDATE واحد لكل دفعة). متزامن جوه نفس الطلب، لإن تغيير
 * مرجعية حاجة نادرة (الأدمن) والأماكن تحت التصنيف الواحد بالمئات. موصّل من `AppServiceProvider` على `saved`.
 *
 * بيتجاهل العنصر الجديد (مفيش مكان تحته)، وأي حقل مش داخل في النص (الأيقونة واللون والترتيب).
 */
final class CatalogSearchRefresh
{
    /** الحقول اللي بتدخل في `search_text` لكل مرجعية (الخاصية المخفية بتطلع من النص). */
    private const WATCHED = [
        Category::class => ['name_ar', 'name_en'],
        Attribute::class => ['name_ar', 'name_en', 'is_active'],
        Area::class => ['name_ar', 'name_en', 'aliases'],
    ];

    private const CHUNK = 200;

    /**
     * @return int عدد الأماكن اللي اتعاد بناؤها
     */
    public static function handle(Model $item): int
    {
        $fields = self::WATCHED[$item::class] ?? null;

        if ($fields === null || $item->wasRecentlyCreated || ! $item->wasChanged($fields)) {
            return 0;
        }

        $ids = match (true) {
            $item instanceof Category => DB::table('place_category')->where('category_id', $item->getKey())->pluck('place_id')
                ->merge(DB::table('places')->where('primary_category_id', $item->getKey())->pluck('id')),
            $item instanceof Attribute => DB::table('attribute_place')->where('attribute_id', $item->getKey())->pluck('place_id'),
            default => DB::table('places')->where('area_id', $item->getKey())->pluck('id'),
        };

        $rebuilt = 0;

        // المحذوف (soft) ما بيظهرش في البحث، ولو اتسترجع الحفظ بيبني نصه
        foreach ($ids->unique()->chunk(self::CHUNK) as $chunk) {
            $live = DB::table('places')->whereIn('id', $chunk->all())->whereNull('deleted_at')->pluck('id')->all();
            $rebuilt += PlaceSearchText::rebuild($live);
        }

        SearchVocabulary::forget();

        return $rebuilt;
    }
}
