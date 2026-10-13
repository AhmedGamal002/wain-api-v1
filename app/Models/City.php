<?php

namespace App\Models;

use App\Models\Concerns\IsCatalogItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * المدينة (A4): القاهرة، الجيزة... المناطق بتتبع مدينة. المدينة المخفية بتخفي مناطقها من الـ API.
 */
class City extends Model
{
    use IsCatalogItem;

    public const LOG_NAME = 'cities';

    public const LOGGED_FIELDS = ['slug', 'name_ar', 'name_en', 'sort_order', 'is_active'];

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** اسم المدينة بيظهر مع المنطقة في `GET /areas`، فتعديلها بيمسح cache المناطق. */
    public static function catalogCacheKeys(): array
    {
        return [Area::CACHE_KEY];
    }

    /**
     * @return HasMany<Area, $this>
     */
    public function areas(): HasMany
    {
        return $this->hasMany(Area::class);
    }

    /**
     * فيها مناطق أو أماكن؟ المدينة المستخدمة بتتخفى بدل ما تتمسح.
     */
    public function isInUse(): bool
    {
        return $this->areas()->exists()
            || Place::withTrashed()->where('city_id', $this->getKey())->exists();
    }
}
