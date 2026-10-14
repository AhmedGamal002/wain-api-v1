<?php

namespace App\Events;

use App\Models\Place;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * بيتطلق من `PlaceObserver::updated` لما `status` المكان يتغيّر (من أزرار القايمة أو من الفورم، الاتنين بيعدّوا
 * على نفس الـ save). `NotifyPlaceStatusChanged` بيبعت إشعارات الجرس منه (03 القسم 4.9). `$actorId` هو اللي
 * عمل التغيير (Auth وقت الحفظ)، وبيتخطى الإشعار لنفسه.
 */
class PlaceStatusChanged
{
    use Dispatchable;

    public function __construct(
        public readonly Place $place,
        public readonly ?string $from,
        public readonly string $to,
        public readonly ?int $actorId,
    ) {}
}
