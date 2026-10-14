<?php

namespace App\Listeners;

use App\Events\PlaceChanged;
use App\Support\Search\PlaceSearchText;
use App\Support\Search\SearchVocabulary;

/**
 * بيبني `places.search_text` مع كل تغيير في المكان (02 F4) ويمسح قاموس التصحيح الإملائي. بيشتغل مباشرة
 * (مش queue): المكان لازم يظهر في البحث بعد الحفظ على طول، والبناء استعلامات قليلة (النص من الاسمين
 * والتصنيفات والخصائص والمنطقة والوصف).
 *
 * الـ Filament form بيطلق `PlaceChanged` تاني بعد ما يحفظ التصنيفات والخصائص، فالنص النهائي بيبان فيه.
 * اسم تصنيف أو خاصية أو منطقة اتغيّر بيتعامل معاه `CatalogSearchRefresh` (الأماكن اللي تحتها ما اتحفظتش، فمفيش
 * `PlaceChanged` ليهم).
 */
class BuildPlaceSearchText
{
    public function handle(PlaceChanged $event): void
    {
        SearchVocabulary::forget();

        $place = $event->place;

        // المحذوف ما بيظهرش في البحث، ولو اتسترجع الحفظ بيطلق الـ event تاني
        if ($place->trashed()) {
            return;
        }

        PlaceSearchText::rebuild([$place->id]);
    }
}
