<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;

/**
 * الـ 8 تصنيفات المبدئية (A2).
 *
 * القائمة دي اقتراح لأن الوثايق ما حدّدتش الأسماء: غيّرها قبل أول استخدام على production،
 * لإن الـ slug ما يتغيرش بعد كده. firstOrCreate عشان إعادة التشغيل ما تمسحش تعديلات الأدمن.
 */
class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['slug' => 'cafes', 'name_ar' => 'كافيهات', 'name_en' => 'Cafes', 'icon' => '☕', 'color' => '#6F4E37'],
            ['slug' => 'restaurants', 'name_ar' => 'مطاعم', 'name_en' => 'Restaurants', 'icon' => '🍽️', 'color' => '#E8593C'],
            ['slug' => 'rooftops', 'name_ar' => 'روف', 'name_en' => 'Rooftops', 'icon' => '🌇', 'color' => '#7B61FF'],
            ['slug' => 'desserts', 'name_ar' => 'حلويات', 'name_en' => 'Desserts', 'icon' => '🍰', 'color' => '#E91E8C'],
            ['slug' => 'fast-food', 'name_ar' => 'وجبات سريعة', 'name_en' => 'Fast food', 'icon' => '🍔', 'color' => '#F5A623'],
            ['slug' => 'parks', 'name_ar' => 'حدائق ومتنزهات', 'name_en' => 'Parks', 'icon' => '🌳', 'color' => '#2E9E5B'],
            ['slug' => 'tourist-spots', 'name_ar' => 'أماكن سياحية', 'name_en' => 'Tourist spots', 'icon' => '🏛️', 'color' => '#1E88E5'],
            ['slug' => 'entertainment', 'name_ar' => 'ترفيه', 'name_en' => 'Entertainment', 'icon' => '🎮', 'color' => '#8E44AD'],
        ];

        foreach ($categories as $index => $category) {
            Category::query()->firstOrCreate(
                ['slug' => $category['slug']],
                $category + ['sort_order' => $index + 1, 'is_active' => true],
            );
        }

        // الـ seeder بيشتغل من غير model events (WithoutModelEvents)
        Cache::forget(Category::CACHE_KEY);
    }
}
