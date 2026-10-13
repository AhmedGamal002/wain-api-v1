<?php

namespace App\Models;

use App\Models\Concerns\IsCatalogItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * المنطقة (A4): التجمع الخامس، الزمالك... بتتعرض في كارت المكان، والـ `slug` بتاعها هو بارامتر `area`
 * في الـ API ومابيتغيرش بعد الإنشاء.
 *
 * `places_count` في `GET /areas` بيتحسب من الأماكن المنشورة وبيتخزّن في cache ساعة مع الباقي؛
 * `ForgetPlaceDetailCache` (listener على `PlaceChanged`) بيمسح `Area::CACHE_KEY` لما مكان يتنشر أو يتخفى.
 */
class Area extends Model
{
    use IsCatalogItem;

    /** مفتاح الـ cache (file) لقايمة الـ API، ساعة، ويتمسح مع أي تعديل في المناطق أو المدن. */
    public const CACHE_KEY = 'areas';

    public const LOG_NAME = 'areas';

    public const LOGGED_FIELDS = ['city_id', 'slug', 'name_ar', 'name_en', 'aliases', 'center_lat', 'center_lng', 'sort_order', 'is_active'];

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'aliases' => 'array',
            'center_lat' => 'float',
            'center_lng' => 'float',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public static function catalogCacheKeys(): array
    {
        return [self::CACHE_KEY];
    }

    /**
     * @return BelongsTo<City, $this>
     */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    /**
     * @return HasMany<Place, $this>
     */
    public function places(): HasMany
    {
        return $this->hasMany(Place::class);
    }

    /**
     * فيها أي مكان (حتى المحذوف soft)؟ المنطقة المستخدمة بتتخفى بدل ما تتمسح.
     */
    public function isInUse(): bool
    {
        return Place::withTrashed()->where('area_id', $this->getKey())->exists();
    }
}
