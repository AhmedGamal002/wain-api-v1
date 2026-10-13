<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Cache;

/**
 * @group Categories
 */
class CategoryController extends Controller
{
    /**
     * List categories
     *
     * قائمة التصنيفات. التصنيفات الظاهرة بالترتيب اللي حدده الأدمن (A2): شريط الرئيسية وألوان الـ pins على الخريطة.
     * التصنيف المخفي ما بيرجعش. الـ `slug` ما بيتغيرش، فالتطبيق بيفلتر بيه. بيتخزن في cache ساعة وبيتمسح مع
     * أي تعديل من اللوحة.
     *
     * @response 200 {"data": [{"slug": "cafes", "name": "كافيهات", "icon": "☕", "color": "#6F4E37"}, {"slug": "restaurants", "name": "مطاعم", "icon": "🍽️", "color": "#E8593C"}]}
     */
    public function index(): AnonymousResourceCollection
    {
        // بيتخزن الصفوف الخام (باللغتين) في الـ cache، واللغة بتتطبّق بعد الـ cache.
        // بنخزّن arrays مش Eloquent models: `cache.serializable_classes = false` بيمنع unserialize للـ objects.
        $rows = Cache::remember(
            Category::CACHE_KEY,
            now()->addHour(),
            fn () => Category::query()->active()->orderBy('sort_order')->orderBy('id')->get()
                ->map(fn (Category $category) => $category->getAttributes())
                ->all(),
        );

        return CategoryResource::collection(Category::hydrate($rows));
    }
}
