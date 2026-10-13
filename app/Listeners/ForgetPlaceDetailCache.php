<?php

namespace App\Listeners;

use App\Events\PlaceChanged;
use App\Models\Area;
use App\Models\Place;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * بيمسح الـ cache اللي المكان بيأثّر عليه: تفاصيل المكان (`places:{slug}`، 5 دقايق) بالـ slug الحالي والقديم
 * وكل slug قديم محفوظ في `place_slug_redirects`، وقايمة المناطق (`areas`) لإن `places_count` فيها بيتغيّر
 * لما مكان يتنشر أو يتخفى أو يتحذف أو يتنقل لمنطقة تانية.
 *
 * بيشتغل مباشرة (مش queue): الإخفاء لازم يبان في أقل من دقيقة (03 القسم 8 «الـ API»). cache القوائم
 * (`places:version`) تبع معلوم (أسبوع 8) وبيشترك في نفس الـ event.
 */
class ForgetPlaceDetailCache
{
    public function handle(PlaceChanged $event): void
    {
        $place = $event->place;

        $slugs = DB::table('place_slug_redirects')->where('place_id', $place->id)->pluck('old_slug')->all();
        $slugs[] = $place->slug;
        // لو الـ slug اتغيّر في نفس الحفظ، الـ original لسه القديم (الـ event بيتطلق من saved)
        $slugs[] = $place->getOriginal('slug');

        foreach (array_unique(array_filter($slugs, 'is_string')) as $slug) {
            Cache::forget(Place::detailsCacheKey($slug));
        }

        Cache::forget(Area::CACHE_KEY);
    }
}
