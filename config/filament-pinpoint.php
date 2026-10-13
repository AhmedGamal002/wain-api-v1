<?php

/**
 * إعدادات `fahiem/filament-pinpoint` (الـ map picker في تاب «الموقع» لفورم المكان). بتتدمج فوق إعدادات الباكدج،
 * فالمفاتيح اللي هنا هي اللي بتغلب. قرار الاختيار في `03-Feature-Places.md` القسم 8 («قرار الـ map picker»).
 *
 * التحويل لـ Google Maps بعد مراجعة التسعير: `PINPOINT_PROVIDER=google` و`GOOGLE_MAPS_API_KEY` (مفتاح ويب مقيَّد
 * بدومين اللوحة). تنبيه: التعديلات اللي في `resources/views/vendor/filament-pinpoint/pinpoint-leaflet.blade.php`
 * (تحريك الـ pin من «استخرج الموقع» و`invalidateSize` جوه التابز) على Leaflet بس، فلازم تتنقل لـ view Google قبل
 * التحويل.
 */
return [
    'provider' => env('PINPOINT_PROVIDER', 'leaflet'),

    'api_key' => env('GOOGLE_MAPS_API_KEY'),

    // الافتراضي في الباكدج إندونيسيا: بنفتح على القاهرة لحد ما الـ pin يتحط
    'default' => [
        'lat' => 30.0444,
        'lng' => 31.2357,
        'zoom' => 15,
        'height' => 380,
    ],
];
