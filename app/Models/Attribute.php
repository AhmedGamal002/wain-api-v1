<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * الخاصية (A3): واي فاي، هادي، مناسب للعيلات... كل خاصية نعم أو لأ ومتقسمة لـ 4 مجموعات.
 * الـ slug ما يتغيرش بعد الإنشاء لإن الفلاتر والـ API والـ CSV بيستخدموه.
 *
 * ملحوظة: العلاقة في `Place` اسمها `placeAttributes` مش `attributes`، لإن `$place->attributes`
 * هي خاصية Eloquent الداخلية نفسها.
 */
class Attribute extends Model
{
    /** المجموعات بترتيب العرض في صفحة المكان (A3). */
    public const GROUPS = ['vibe', 'services', 'suitable_for', 'facilities'];

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

    /**
     * @param  Builder<Attribute>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('attributes.is_active', true);
    }

    /**
     * الاسم بلغة الطلب، ولو الإنجليزي فاضي بيرجع العربي.
     */
    public function localizedName(): string
    {
        if (app()->getLocale() === 'en' && filled($this->name_en)) {
            return $this->name_en;
        }

        return $this->name_ar;
    }
}
