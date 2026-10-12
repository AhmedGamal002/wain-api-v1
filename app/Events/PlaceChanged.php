<?php

namespace App\Events;

use App\Models\Place;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * بيتطلق من PlaceObserver بعد أي حفظ أو تغيير حالة (جمال، A1 أسبوع 4). كل domain بيشترك بـ listener
 * خاص بيه: place_hours (هنا) وsearch_text والـ cache (معلوم، أسابيع 6 و8). متحطّش منطقهم جوه الـ Observer.
 */
class PlaceChanged
{
    use Dispatchable;

    public function __construct(public readonly Place $place) {}
}
