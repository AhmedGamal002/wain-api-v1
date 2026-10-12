<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * القائمة المبدئية للخصائص (A3 في 02-Feature-Specs.md).
 *
 * الـ slugs اقتراح (الوثائق ذكرت `wifi` و`quiet` بس): غيّرها قبل أول استخدام على production،
 * لإن الفلاتر والـ API والـ CSV بيستخدموها وما بتتغيرش بعد كده. insertOrIgnore عشان إعادة التشغيل
 * ما تمسحش تعديلات الأدمن. أقصى 6 chips سريعة.
 */
class AttributeSeeder extends Seeder
{
    /** @var list<string> */
    public const QUICK_FILTERS = ['wifi', 'quiet', 'shisha', 'family', 'view', 'power-outlets'];

    public function run(): void
    {
        $groups = [
            'vibe' => [
                ['quiet', 'هادي', 'Quiet', '🤫'],
                ['lively', 'مزدحم وفيه حياة', 'Lively', '🎉'],
                ['romantic', 'رومانسي', 'Romantic', '💕'],
                ['view', 'فيو', 'View', '🌆'],
                ['outdoor', 'مكان مفتوح', 'Outdoor', '🌿'],
                ['live-music', 'موسيقى لايف', 'Live music', '🎵'],
            ],
            'services' => [
                ['wifi', 'واي فاي', 'Wi-Fi', '📶'],
                ['power-outlets', 'فيش كهربا', 'Power outlets', '🔌'],
                ['shisha', 'شيشة', 'Shisha', '💨'],
                ['corner-seating', 'ركنة', 'Corner seating', '🛋️'],
                ['card-payment', 'دفع بالفيزا', 'Card payment', '💳'],
                ['reservations', 'حجز', 'Reservations', '📅'],
                ['delivery', 'ديليفري', 'Delivery', '🛵'],
            ],
            'suitable_for' => [
                ['study-work', 'المذاكرة والشغل', 'Study & work', '📚'],
                ['family', 'العيلة', 'Family', '👨‍👩‍👧'],
                ['kids', 'الأطفال', 'Kids', '🧒'],
                ['large-groups', 'الجروبات الكبيرة', 'Large groups', '👥'],
                ['dates', 'المواعيد', 'Dates', '💑'],
            ],
            'facilities' => [
                ['prayer-room', 'مصلى', 'Prayer room', '🕌'],
                ['non-smoking', 'منطقة غير مدخنين', 'Non-smoking area', '🚭'],
                ['accessible', 'مناسب لذوي الهمم', 'Accessible', '♿'],
                ['ac', 'تكييف', 'Air conditioning', '❄️'],
            ],
        ];

        $order = 0;

        foreach ($groups as $group => $attributes) {
            foreach ($attributes as [$slug, $nameAr, $nameEn, $icon]) {
                DB::table('attributes')->insertOrIgnore([
                    'slug' => $slug,
                    'name_ar' => $nameAr,
                    'name_en' => $nameEn,
                    'group' => $group,
                    'icon' => $icon,
                    'is_filterable' => true,
                    'is_quick_filter' => in_array($slug, self::QUICK_FILTERS, true),
                    'sort_order' => ++$order,
                    'is_active' => true,
                ]);
            }
        }
    }
}
