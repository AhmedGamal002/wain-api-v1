<?php

namespace App\Listeners;

use App\Events\PlaceChanged;
use App\Support\PlaceListCache;

/**
 * أي حفظ أو تغيير حالة أو حذف في مكان بيغيّر نسخة cache القوايم (`places:version`)، فالقوايم المخزّنة (60 ثانية)
 * بتتعامل كـ miss من أول طلب بعده. بيشتغل مباشرة (مش queue) زي `ForgetPlaceDetailCache`: الإخفاء لازم يبان في أقل
 * من دقيقة (03 القسم 8).
 */
class BumpPlaceListVersion
{
    public function handle(PlaceChanged $event): void
    {
        PlaceListCache::bump();
    }
}
