<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Attribute;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/**
 * @group Attributes
 */
class AttributeController extends Controller
{
    /**
     * List attributes
     *
     * الخصائص الفعّالة (واي فاي، هادي...) مجمّعة حسب المجموعة بترتيب العرض: الجو، الخدمات، مناسب لـ، المكان.
     * المجموعة الفاضية ما بترجعش، والخاصية المخفية ما بترجعش.
     *
     * - `is_filterable`: تظهر في شاشة الفلاتر (S33).
     * - `is_quick_filter`: تظهر كـ chip سريعة تحت خانة البحث (أقصى 6).
     * - الخصائص كلها (حتى اللي مش `is_filterable`) تنفع في تقييم المكان.
     *
     * بيتخزن في cache ساعة وبيتمسح مع أي تعديل من اللوحة. اللغة من `Accept-Language`.
     *
     * @response 200 {"data": [{"key": "vibe", "name": "الجو", "items": [{"slug": "quiet", "name": "هادي", "icon": "🤫", "is_filterable": true, "is_quick_filter": true}]}, {"key": "services", "name": "الخدمات", "items": [{"slug": "wifi", "name": "واي فاي", "icon": "📶", "is_filterable": true, "is_quick_filter": true}]}]}
     */
    public function index(): JsonResponse
    {
        // بنخزّن الصفوف الخام (باللغتين) arrays مش Eloquent models: `cache.serializable_classes = false`.
        // اللغة بتتطبّق بعد الـ cache.
        $rows = Cache::remember(
            Attribute::CACHE_KEY,
            now()->addHour(),
            fn () => Attribute::query()->active()->orderBy('sort_order')->orderBy('id')->get()
                ->map(fn (Attribute $attribute) => $attribute->getAttributes())
                ->all(),
        );

        $byGroup = Attribute::hydrate($rows)->groupBy('group');

        $groups = [];
        foreach (Attribute::GROUPS as $group) {
            $items = $byGroup->get($group);

            if ($items === null || $items->isEmpty()) {
                continue;
            }

            $groups[] = [
                'key' => $group,
                'name' => __('places.attribute_groups.'.$group),
                'items' => $items->map(fn (Attribute $attribute) => [
                    'slug' => $attribute->slug,
                    'name' => $attribute->localizedName(),
                    'icon' => $attribute->icon,
                    'is_filterable' => $attribute->is_filterable,
                    'is_quick_filter' => $attribute->is_quick_filter,
                ])->values()->all(),
            ];
        }

        return response()->json(['data' => $groups]);
    }
}
