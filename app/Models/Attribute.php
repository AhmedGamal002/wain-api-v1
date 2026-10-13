<?php

namespace App\Models;

use App\Models\Concerns\IsCatalogItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * الخاصية (A3): واي فاي، هادي، مناسب للعيلات... كل خاصية نعم أو لأ ومتقسمة لـ 4 مجموعات.
 * الـ slug ما يتغيرش بعد الإنشاء لإن الفلاتر والـ API والـ CSV بيستخدموه.
 *
 * ملحوظة: العلاقة في `Place` اسمها `placeAttributes` مش `attributes`، لإن `$place->attributes`
 * هي خاصية Eloquent الداخلية نفسها.
 */
class Attribute extends Model
{
    use IsCatalogItem;

    /** المجموعات بترتيب العرض في صفحة المكان والفلاتر (A3). */
    public const GROUPS = ['vibe', 'services', 'suitable_for', 'facilities'];

    /** أقصى عدد chips سريعة تحت خانة البحث (A3). القيد في الموديل والـ Filament مش في الداتابيز. */
    public const MAX_QUICK_FILTERS = 6;

    /** مفتاح الـ cache (file) لقايمة الـ API، ساعة، ويتمسح مع أي تعديل. */
    public const CACHE_KEY = 'attributes';

    public const LOG_NAME = 'attributes';

    public const LOGGED_FIELDS = ['slug', 'name_ar', 'name_en', 'group', 'icon', 'is_filterable', 'is_quick_filter', 'sort_order', 'is_active'];

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_filterable' => 'boolean',
            'is_quick_filter' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // الـ chip السريعة لازم تكون ظاهرة ومتاحة في الفلاتر: لو الخاصية اتخفت أو اتشالت من الفلاتر
        // بتطلع من الـ chips تلقائياً، فعدد الـ chips في الداتابيز هو الفعلي دايماً.
        // `=== false` مش `! $x`: خاصية جديدة من غير is_active (null) هياخد default الداتابيز (true).
        static::saving(function (Attribute $attribute) {
            if ($attribute->is_active === false || $attribute->is_filterable === false) {
                $attribute->is_quick_filter = false;
            }
        });
    }

    public static function catalogCacheKeys(): array
    {
        return [self::CACHE_KEY];
    }

    /**
     * @return BelongsToMany<Place, $this>
     */
    public function places(): BelongsToMany
    {
        return $this->belongsToMany(Place::class, 'attribute_place');
    }

    /**
     * مستخدمة في أي مكان (حتى المحذوف soft)؟ الـ FK `restrictOnDelete` بيرفض الحذف: بتتخفى بس.
     */
    public function isInUse(): bool
    {
        return $this->places()->withTrashed()->exists();
    }

    /**
     * وصلنا للحد الأقصى من الـ chips السريعة (من غير السجل نفسه)؟
     */
    public static function quickFilterLimitReached(?self $except = null): bool
    {
        return static::query()
            ->where('is_quick_filter', true)
            ->when($except?->exists, fn (Builder $query) => $query->whereKeyNot($except->getKey()))
            ->count() >= self::MAX_QUICK_FILTERS;
    }
}
