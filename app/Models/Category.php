<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * التصنيف (A2). الـ slug ما يتغيرش بعد الإنشاء لإن التطبيق بيفلتر بيه.
 */
class Category extends Model
{
    use LogsActivity;

    /** مفتاح الـ cache (file) لقائمة الظاهر، ساعة، ويتمسح مع أي حفظ أو حذف. */
    public const CACHE_KEY = 'categories';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Category $category) {
            // الـ slug تلقائي من الاسم الإنجليزي، والترتيب في آخر القائمة (A2)
            if (blank($category->slug)) {
                $category->slug = self::uniqueSlug((string) $category->name_en);
            }

            if ($category->sort_order === null || $category->sort_order === 0) {
                $category->sort_order = ((int) static::query()->max('sort_order')) + 1;
            }
        });

        // الـ slug ما يتغيرش بعد الإنشاء: أي محاولة تعديل بتتجاهل (التطبيق والفلاتر بيستخدموه)
        static::updating(function (Category $category) {
            if ($category->isDirty('slug')) {
                $category->slug = $category->getOriginal('slug');
            }
        });

        static::saved(fn () => Cache::forget(self::CACHE_KEY));
        static::deleted(fn () => Cache::forget(self::CACHE_KEY));
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('categories')
            ->logOnly(['slug', 'name_ar', 'name_en', 'icon', 'color', 'sort_order', 'is_active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
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

    /**
     * @param  Builder<Category>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * الاسم بلغة الطلب، ولو الإنجليزي فاضي بيرجع العربي (03 القسم 5.1).
     */
    public function localizedName(): string
    {
        if (app()->getLocale() === 'en' && filled($this->name_en)) {
            return $this->name_en;
        }

        return $this->name_ar;
    }

    private static function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'category';
        $slug = $base;
        $suffix = 2;

        while (static::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}
