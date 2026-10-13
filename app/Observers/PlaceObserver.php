<?php

namespace App\Observers;

use App\Events\PlaceChanged;
use App\Models\Place;
use App\Support\PlaceLocation;
use App\Support\PlaceSlug;
use App\Support\PriceRange;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * بيملى الحقول المشتقة للمكان ويطلق `PlaceChanged` بعد أي حفظ أو حذف أو استرجاع (03 القسم 5.5).
 *
 * اللي هنا مشتق من حقول المكان نفسه بس: الـ slug و`location` و`price_range` و`tier_rank` و`published_at`
 * وحفظ الـ slug القديم. أي حاجة بتبني حاجة تانية (`search_text` و`place_hours` والـ cache) listener على
 * `PlaceChanged`، ومتتحطّش هنا (CLAUDE.md «الملكية والـ PR»).
 */
class PlaceObserver
{
    public function creating(Place $place): void
    {
        if (blank($place->slug)) {
            $place->slug = PlaceSlug::generate((string) $place->name_en, $place->area_id);
        }

        $place->created_by ??= Auth::id();
    }

    public function saving(Place $place): void
    {
        $creating = ! $place->exists;

        // POINT(lng lat) من PlaceLocation بس، عشان المحاور ما تتبدّلش (CLAUDE.md «الـ spatial»)
        if ($creating || $place->isDirty(['latitude', 'longitude'])) {
            $place->location = PlaceLocation::point((float) $place->latitude, (float) $place->longitude);
        }

        if ($creating || $place->isDirty('price_max')) {
            [$budgetMax, $midMax] = $this->priceThresholds();
            $place->price_range = PriceRange::forMaxPrice($place->price_max, $budgetMax, $midMax);
        }

        if ($creating || $place->isDirty('listing_tier')) {
            $place->tier_rank = Place::TIER_RANKS[$place->listing_tier] ?? Place::TIER_RANKS['free'];
        }

        // القفل النهائي بيرجّع الباقة free (03 القسم 2 «جدول الانتقالات»)
        if ($place->status === Place::STATUS_CLOSED && $place->isDirty('status')) {
            $place->listing_tier = 'free';
            $place->tier_rank = Place::TIER_RANKS['free'];
            $place->tier_starts_at = null;
            $place->tier_ends_at = null;
        }

        // أول مرة اتنشر (لـ «جديد» والترتيب بالأحدث). الإخفاء والإظهار بعد كده ما بيغيّروش.
        if ($place->status === Place::STATUS_ACTIVE && $place->published_at === null) {
            $place->published_at = now();
        }

        // آخر من عدّل: بس لو فيه تعديل فعلاً، عشان حفظ من غير تغيير ما يغيّرش الاسم
        if (Auth::id() !== null && $place->isDirty()) {
            $place->updated_by = Auth::id();
        }
    }

    public function updating(Place $place): void
    {
        if (! $place->isDirty('slug')) {
            return;
        }

        $original = (string) $place->getOriginal('slug');

        // الـ slug مابيبقاش فاضي
        if (blank($place->slug)) {
            $place->slug = $original;

            return;
        }

        // بعد أول نشر الرابط القديم ممكن يكون اتشارك: بيتحفظ عشان يفضل شغال (03 القسم 7.7). والرجوع لـ slug
        // قديم بيشيله من الـ redirects.
        if ($place->published_at !== null) {
            DB::table('place_slug_redirects')
                ->where('place_id', $place->id)->where('old_slug', $place->slug)->delete();
            DB::table('place_slug_redirects')->insertOrIgnore([
                'old_slug' => $original,
                'place_id' => $place->id,
            ]);
        }
    }

    public function saved(Place $place): void
    {
        // `location` اتكتب كـ SQL expression، فبنشيله من الـ attributes عشان ما يتقراش بعد كده كقيمة
        $place->offsetUnset('location');

        PlaceChanged::dispatch($place);
    }

    public function deleted(Place $place): void
    {
        PlaceChanged::dispatch($place);
    }

    // الاسترجاع (`restore`) بيعدّي على `saved` لإنه بيحفظ deleted_at = null، فمفيش handler ليه

    public function forceDeleted(Place $place): void
    {
        PlaceChanged::dispatch($place);
    }

    /**
     * حدود فئات السعر من `settings` (03 القسم 7.2)، أو الافتراضي لو الإعداد مش موجود.
     *
     * @return array{0: int, 1: int}
     */
    private function priceThresholds(): array
    {
        $values = DB::table('settings')
            ->whereIn('key', ['price_budget_max', 'price_mid_max'])
            ->pluck('value', 'key');

        return [
            (int) ($values['price_budget_max'] ?? PriceRange::BUDGET_MAX),
            (int) ($values['price_mid_max'] ?? PriceRange::MID_MAX),
        ];
    }
}
