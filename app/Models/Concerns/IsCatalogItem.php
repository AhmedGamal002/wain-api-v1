<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * القاسم المشترك لجداول المرجعيات اللي الأدمن بيديرها (A2 التصنيفات، A3 الخصائص، A4 المدن والمناطق):
 *
 * - الـ slug بيتولّد من `name_en` وقت الإنشاء وما بيتغيرش بعد كده (التطبيق والفلاتر والـ API بيستخدموه).
 * - `sort_order` بيتحط في آخر القائمة لو ما اتحددش.
 * - مفاتيح الـ cache بتتمسح مع أي حفظ أو حذف (`catalogCacheKeys`). الـ reorder بالسحب في Filament بيعمل
 *   UPDATE واحد من غير model events، فلازم الـ afterReordering يمسحها صراحةً (CatalogCache).
 * - كل تغيير بيتسجل في سجل التغييرات (activitylog).
 *
 * الموديل اللي بيستخدمه لازم يعرّف `LOG_NAME` و`LOGGED_FIELDS` و`catalogCacheKeys()` و`isInUse()`.
 */
trait IsCatalogItem
{
    use LogsActivity;

    /**
     * مفاتيح الـ cache اللي بتتمسح لما السجل يتغيّر.
     *
     * @return list<string>
     */
    abstract public static function catalogCacheKeys(): array;

    /**
     * السجل مستخدم في حاجة؟ لو أيوه ما يتمسحش، بيتخفى بس (الـ FK restrictOnDelete بيرفض الحذف كمان).
     */
    abstract public function isInUse(): bool;

    public static function bootIsCatalogItem(): void
    {
        static::creating(function (Model $model) {
            if (blank($model->slug)) {
                $model->slug = static::uniqueSlugFor((string) $model->name_en);
            }

            // «مش محدد» (null) بس: الصفر قيمة صريحة
            if ($model->sort_order === null) {
                $model->sort_order = ((int) static::query()->max('sort_order')) + 1;
            }
        });

        // أي محاولة تعديل للـ slug بتتجاهل
        static::updating(function (Model $model) {
            if ($model->isDirty('slug')) {
                $model->slug = $model->getOriginal('slug');
            }
        });

        $forget = fn () => static::forgetCatalogCache();
        static::saved($forget);
        static::deleted($forget);
    }

    public static function forgetCatalogCache(): void
    {
        foreach (static::catalogCacheKeys() as $key) {
            Cache::forget($key);
        }
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName(static::LOG_NAME)
            ->logOnly(static::LOGGED_FIELDS)
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where($this->qualifyColumn('is_active'), true);
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

    protected static function uniqueSlugFor(string $name): string
    {
        $base = Str::slug($name) ?: Str::kebab(class_basename(static::class));
        $slug = $base;
        $suffix = 2;

        while (static::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}
