<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * المكان (03 القسم 2). الموديل ده بيحتوي على اللي الـ API محتاجه لقراءة الأماكن (القوائم والتفاصيل).
 * الـ Observer والـ Policy وPlacePublishCheck وحالات الـ Dashboard بتتضاف مع A1 (أسبوع 4).
 */
class Place extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'search_aliases' => 'array',
            'opening_hours' => 'array',
            'ramadan_hours' => 'array',
            'latitude' => 'float',
            'longitude' => 'float',
            'price_min' => 'integer',
            'price_max' => 'integer',
            'rating_avg' => 'float',
            'rating_count' => 'integer',
            'tier_rank' => 'integer',
            'published_at' => 'datetime',
            'verified_at' => 'datetime',
            'closed_at' => 'date',
        ];
    }

    /** @return BelongsTo<Category, $this> */
    public function primaryCategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'primary_category_id');
    }

    /** @return BelongsTo<Area, $this> */
    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    /** @return BelongsTo<PlacePhoto, $this> */
    public function coverPhoto(): BelongsTo
    {
        return $this->belongsTo(PlacePhoto::class, 'cover_photo_id');
    }

    /**
     * قاعدة الظهور (03 القسم 5.1): الـ API بيرجّع المنشور بس. المخفي والمسودة وقيد المراجعة
     * والمرفوض والمغلق نهائياً ما بيظهروش في أي قائمة.
     *
     * @param  Builder<Place>  $query
     */
    public function scopePublished(Builder $query): void
    {
        $query->where('places.status', 'active');
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
