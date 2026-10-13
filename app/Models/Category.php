<?php

namespace App\Models;

use App\Models\Concerns\IsCatalogItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * التصنيف (A2). الـ slug ما يتغيرش بعد الإنشاء لإن التطبيق بيفلتر بيه.
 */
class Category extends Model
{
    use IsCatalogItem;

    /** مفتاح الـ cache (file) لقائمة الظاهر، ساعة، ويتمسح مع أي حفظ أو حذف. */
    public const CACHE_KEY = 'categories';

    public const LOG_NAME = 'categories';

    public const LOGGED_FIELDS = ['slug', 'name_ar', 'name_en', 'icon', 'color', 'sort_order', 'is_active'];

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public static function catalogCacheKeys(): array
    {
        return [self::CACHE_KEY];
    }

    /**
     * أماكن التصنيف (الرئيسي أو الإضافي). من غير المحذوفة (SoftDeletes في Place).
     *
     * @return BelongsToMany<Place, $this>
     */
    public function places(): BelongsToMany
    {
        return $this->belongsToMany(Place::class, 'place_category');
    }

    /**
     * فيه أي مكان (حتى المحذوف soft) مربوط بالتصنيف؟ ده شرط منع الحذف (A2): الـ FK `restrictOnDelete`
     * بيرفض الحذف كمان، فالتصنيف اللي فيه أماكن بيتخفى بدل ما يتحذف.
     */
    public function hasPlaces(): bool
    {
        return $this->places()->withTrashed()->exists()
            || Place::withTrashed()->where('primary_category_id', $this->getKey())->exists();
    }

    public function isInUse(): bool
    {
        return $this->hasPlaces();
    }
}
