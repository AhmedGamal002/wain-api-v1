<?php

namespace App\Http\Requests;

use App\Models\Place;
use App\Support\OpenNow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * فلاتر قائمة الأماكن (F5، 02 القسم F5، 03 القسم 5.1). مشتركة بين `GET /places` و`GET /places/count`
 * عشان العدد اللي على زرار «ورّيني 23 مكان» يطابق القائمة بالظبط من نفس القواعد ونفس الاستعلام.
 *
 * | الفلتر            | البارامتر               | المنطق                                                     |
 * | المنطقة           | `area`                  | واحدة                                                      |
 * | التصنيف           | `categories[]`          | OR                                                         |
 * | الخصائص (الجو)    | `attributes[]`          | AND: المكان لازم يكون فيه كل الخصائص المختارة              |
 * | سعر الفرد         | `price_min`، `price_max` | نطاق المكان بيتقاطع مع النطاق المختار (من غير حد = مفتوح) |
 * | التقييم           | `rating_min`            | 3 أو 4 أو 4.5 فأكتر                                        |
 * | مفتوح دلوقتي      | `open_now`              | بتوقيت القاهرة                                             |
 */
abstract class PlaceFilterRequest extends FormRequest
{
    public const MAX_CATEGORIES = 8;

    public const MAX_ATTRIBUTES = 10;

    public const MAX_PRICE = 100000;

    /**
     * فلتر التقييم بيحسب الأماكن اللي ليها 3 تقييمات أو أكتر بس. المكان اللي تحت 3 بيظهر «جديد» من غير
     * رقم (03 القسم 7.6)، فلو دخل في «4+» المستخدم هيشوف كارت من غير تقييم في نتايج بتقول «4 فأكتر».
     */
    public const MIN_RATINGS_FOR_RATING_FILTER = 3;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function filterRules(): array
    {
        return [
            'area' => ['nullable', 'string', Rule::exists('areas', 'slug')],
            'categories' => ['nullable', 'array', 'max:'.self::MAX_CATEGORIES],
            'categories.*' => ['string', Rule::exists('categories', 'slug')],
            // الخصائص اللي الأدمن سمح بالفلترة بيها وظاهرة بس (A3)
            'attributes' => ['nullable', 'array', 'max:'.self::MAX_ATTRIBUTES],
            'attributes.*' => [
                'string',
                Rule::exists('attributes', 'slug')->where('is_filterable', true)->where('is_active', true),
            ],
            'price_min' => ['nullable', 'integer', 'min:0', 'max:'.self::MAX_PRICE],
            'price_max' => [
                'nullable',
                'integer',
                'min:0',
                'max:'.self::MAX_PRICE,
                // gte على حقل مش موجود بيفشل، فمشروط بوجود price_min
                Rule::when($this->filled('price_min'), ['gte:price_min']),
            ],
            'rating_min' => ['nullable', 'numeric', 'between:1,5'],
            // «مفتوح دلوقتي» بتوقيت القاهرة (03 القسم 7.1): المكان من غير مواعيد ما بيظهرش
            'open_now' => ['nullable', 'boolean'],
        ];
    }

    /**
     * وصف البارامترات لتوثيق Scribe.
     *
     * @return array<string, array<string, mixed>>
     */
    public function queryParameters(): array
    {
        return [
            'area' => ['description' => 'slug المنطقة (من `GET /areas`).', 'example' => 'new-cairo'],
            'categories' => ['description' => 'slugs التصنيفات. المكان يظهر لو أي تصنيف من تصنيفاته مطابق (OR).', 'example' => ['cafes', 'rooftops']],
            'attributes' => ['description' => 'slugs الخصائص من `GET /attributes`. المكان لازم يكون فيه **كلها** (AND).', 'example' => ['wifi', 'quiet']],
            'price_min' => ['description' => 'أقل سعر فرد بالجنيه. المكان يظهر لو نطاق سعره بيتقاطع مع النطاق المختار.', 'example' => 100],
            'price_max' => ['description' => 'أعلى سعر فرد بالجنيه، ولازم يكون أكبر من أو يساوي `price_min`.', 'example' => 300],
            'rating_min' => ['description' => 'أقل تقييم (3 أو 4 أو 4.5). بيحسب الأماكن اللي ليها 3 تقييمات أو أكتر بس.', 'example' => 4],
            'open_now' => ['description' => 'الأماكن المفتوحة دلوقتي بتوقيت القاهرة (`1`).', 'example' => 1],
        ];
    }

    /**
     * بيطبّق الفلاتر المختارة على استعلام الأماكن. الفلتر اللي ما اتبعتش ما بيأثرش.
     *
     * @param  Builder<Place>  $query
     */
    public function applyFilters(Builder $query, OpenNow $now): void
    {
        $filters = $this->validated();

        if (! empty($filters['area'])) {
            $query->whereHas('area', fn (Builder $area) => $area->where('slug', $filters['area']));
        }

        if (! empty($filters['categories'])) {
            // تصنيف المكان (الرئيسي أو الإضافي) جوه المختار. الرئيسي دايماً موجود في place_category.
            $query->whereIn('places.id', DB::table('place_category')
                ->join('categories', 'categories.id', '=', 'place_category.category_id')
                ->whereIn('categories.slug', $filters['categories'])
                ->select('place_category.place_id'));
        }

        if (! empty($filters['attributes'])) {
            // AND: المكان اللي عدد الخصائص المختارة اللي عنده = عدد المختار
            $slugs = array_values(array_unique($filters['attributes']));

            $query->whereIn('places.id', DB::table('attribute_place')
                ->join('attributes', 'attributes.id', '=', 'attribute_place.attribute_id')
                ->whereIn('attributes.slug', $slugs)
                ->groupBy('attribute_place.place_id')
                ->havingRaw('COUNT(DISTINCT attributes.id) = ?', [count($slugs)])
                ->select('attribute_place.place_id'));
        }

        // نطاق المكان [price_min, price_max] بيتقاطع مع المختار [from, to] لو:
        // price_max >= from و price_min <= to. المكان من غير سعر (NULL) ما بيطابقش أي نطاق.
        if (isset($filters['price_min'])) {
            $query->where('places.price_max', '>=', (int) $filters['price_min']);
        }
        if (isset($filters['price_max'])) {
            $query->where('places.price_min', '<=', (int) $filters['price_max']);
        }

        if (isset($filters['rating_min'])) {
            $query
                ->where('places.rating_avg', '>=', (float) $filters['rating_min'])
                ->where('places.rating_count', '>=', self::MIN_RATINGS_FOR_RATING_FILTER);
        }

        if ($this->boolean('open_now')) {
            $query->openNow($now);
        }
    }
}
