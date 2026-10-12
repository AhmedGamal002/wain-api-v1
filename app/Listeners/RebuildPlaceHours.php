<?php

namespace App\Listeners;

use App\Events\PlaceChanged;
use App\Support\PlaceHoursBuilder;

/**
 * بيبني `place_hours` مع كل تغيير في المكان. بيشتغل مباشرة (مش queue) لإن فلتر «مفتوح دلوقتي»
 * لازم يطابق اللي اتحفظ، وصفوف المكان الواحد قليلة (7 أيام × فترتين على الأكتر).
 */
class RebuildPlaceHours
{
    public function handle(PlaceChanged $event): void
    {
        PlaceHoursBuilder::rebuild($event->place->id);
    }
}
