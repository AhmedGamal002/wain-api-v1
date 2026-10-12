<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Cache;

class CategoryController extends Controller
{
    /**
     * GET /api/v1/categories — التصنيفات الظاهرة بالترتيب (A2).
     *
     * بيتخزن الصفوف الخام (باللغتين) في الـ cache، واللغة بتتطبّق بعد الـ cache.
     * بنخزّن arrays مش Eloquent models: `cache.serializable_classes = false` بيمنع unserialize للـ objects.
     */
    public function index(): AnonymousResourceCollection
    {
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
