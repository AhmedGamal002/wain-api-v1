<?php

namespace App\Support;

use App\Models\Place;
use Illuminate\Support\Facades\DB;

/**
 * شروط النشر التسعة (03 القسم 4.3). قائمة واحدة بتتحقق منها 4 أماكن: الـ checklist الجانبية، وعمود «اكتمال
 * البيانات» في القائمة، وأزرار «إرسال للمراجعة» و«نشر» و«إظهار» و«حفظ» على المنشور، والإجراءات الجماعية.
 * مفيش منطق شرط متكرر في أي مكان تاني: كله بيعدّي من هنا.
 *
 * `check()` بياخد لقطة بيانات (array) مش موديل، عشان الـ form في أسبوع 5 يتحقق من اللي اتكتب قبل الحفظ،
 * و`for()` بيبني اللقطة من مكان محفوظ. اللقطة:
 *
 *   name_ar، name_en، primary_category_id، category_ids (list<int>)، area_id، latitude، longitude،
 *   address_ar، description_ar، attribute_count، price_min، price_max، opening_hours (?array)، cover_ready (bool)
 */
final class PlacePublishCheck
{
    /** الشرط => التاب اللي يفتحه لما الـ checklist يتضغط (مفاتيح تابز الـ form في أسبوع 5). */
    public const CONDITIONS = [
        'names' => 'basic',
        'primary_category' => 'category',
        'location' => 'location',
        'address' => 'location',
        'description' => 'basic',
        'attributes' => 'category',
        'price' => 'category',
        'hours' => 'hours',
        'cover' => 'photos',
    ];

    public const LABELS = [
        'names' => 'الاسمين بالعربي والإنجليزي',
        'primary_category' => 'التصنيف الرئيسي',
        'location' => 'المنطقة والموقع',
        'address' => 'العنوان بالعربي',
        'description' => 'الوصف بالعربي',
        'attributes' => '3 خصائص على الأقل',
        'price' => 'سعر الفرد',
        'hours' => 'المواعيد',
        'cover' => 'صورة الغلاف',
    ];

    /** البنود 1 و2 و3 و4 هما كمان الحقول الإلزامية للمسودة. */
    public const DRAFT_REQUIRED = ['names', 'primary_category', 'location', 'address'];

    public const MIN_ATTRIBUTES = 3;

    public const DESCRIPTION_MIN = 50;

    public const DESCRIPTION_MAX = 1000;

    /** @var list<string> مفاتيح حالات اليوم المقبولة في `opening_hours` */
    private const DAY_STATES = ['open', 'closed', '24h'];

    /**
     * بيتحقق من مكان محفوظ. التصنيفات والغلاف وعدد الخصائص بتتقرا من العلاقات لو اتحمّلت (القائمة بتحمّلها
     * بـ `Place::scopeWithPublishCheckData` عشان ما يبقاش فيه N+1)، وإلا من استعلام لكل واحدة.
     */
    public static function for(Place $place): PlacePublishResult
    {
        return self::check([
            'name_ar' => $place->name_ar,
            'name_en' => $place->name_en,
            'primary_category_id' => $place->primary_category_id,
            'category_ids' => $place->categories->modelKeys(),
            'area_id' => $place->area_id,
            'latitude' => $place->latitude,
            'longitude' => $place->longitude,
            'address_ar' => $place->address_ar,
            'description_ar' => $place->description_ar,
            'attribute_count' => $place->place_attributes_count ?? $place->placeAttributes()->count(),
            'price_min' => $place->price_min,
            'price_max' => $place->price_max,
            'opening_hours' => $place->opening_hours,
            'cover_ready' => $place->coverPhoto?->isReady() ?? false,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data  لقطة البيانات (فوق)
     */
    public static function check(array $data): PlacePublishResult
    {
        $categoryIds = array_map('intval', $data['category_ids'] ?? []);
        $primary = $data['primary_category_id'] ?? null;
        $lat = $data['latitude'] ?? null;
        $lng = $data['longitude'] ?? null;
        $description = trim((string) ($data['description_ar'] ?? ''));
        $priceMin = $data['price_min'] ?? null;
        $priceMax = $data['price_max'] ?? null;

        return new PlacePublishResult([
            'names' => self::filled($data['name_ar'] ?? null) && self::filled($data['name_en'] ?? null),
            'primary_category' => $primary !== null && in_array((int) $primary, $categoryIds, true),
            'location' => ($data['area_id'] ?? null) !== null
                && is_numeric($lat) && is_numeric($lng)
                && PlaceLocation::isInsideEgypt((float) $lat, (float) $lng),
            'address' => self::filled($data['address_ar'] ?? null),
            'description' => mb_strlen($description) >= self::DESCRIPTION_MIN
                && mb_strlen($description) <= self::DESCRIPTION_MAX,
            'attributes' => (int) ($data['attribute_count'] ?? 0) >= self::MIN_ATTRIBUTES,
            'price' => $priceMin !== null && $priceMax !== null && (int) $priceMax >= (int) $priceMin,
            'hours' => self::hoursComplete($data['opening_hours'] ?? null),
            'cover' => (bool) ($data['cover_ready'] ?? false),
        ]);
    }

    /**
     * «مفضّل» (مش شرط): التليفون أو الواتساب، 3 صور معرض على الأقل، الوصف الإنجليزي، رابط المنيو.
     * بتظهر في الـ checklist تحت خط «مفضّل» من غير علامة حمرا.
     *
     * @return list<array{key: string, label: string, ok: bool}>
     */
    public static function recommended(Place $place): array
    {
        $gallery = $place->photos_count ?? DB::table('place_photos')
            ->where('place_id', $place->id)->where('type', 'gallery')->where('status', 'ready')->count();

        return self::recommendedFor([
            'phone' => $place->phone,
            'whatsapp' => $place->whatsapp,
            'description_en' => $place->description_en,
            'menu_url' => $place->menu_url,
            'gallery_count' => $gallery,
        ]);
    }

    /**
     * «مفضّل» من لقطة بيانات (الـ form بيستخدمها قبل الحفظ). مفاتيح اللقطة: phone، whatsapp، description_en،
     * menu_url، gallery_count.
     *
     * @param  array<string, mixed>  $data
     * @return list<array{key: string, label: string, ok: bool}>
     */
    public static function recommendedFor(array $data): array
    {
        return [
            ['key' => 'contact', 'label' => 'التليفون أو الواتساب', 'ok' => filled($data['phone'] ?? null) || filled($data['whatsapp'] ?? null)],
            ['key' => 'gallery', 'label' => '3 صور معرض على الأقل', 'ok' => (int) ($data['gallery_count'] ?? 0) >= 3],
            ['key' => 'description_en', 'label' => 'الوصف بالإنجليزي', 'ok' => filled($data['description_en'] ?? null)],
            ['key' => 'menu_url', 'label' => 'رابط المنيو', 'ok' => filled($data['menu_url'] ?? null)],
        ];
    }

    /**
     * ids الأماكن اللي ناقصها شرط نشر (فلتر «ناقص بيانات للنشر» والإجراءات الجماعية). بيمشي على الأماكن
     * على دفعات بنفس الـ check بدل ما يكرر الشروط في SQL، عشان الشرط يفضل واحد.
     *
     * @return list<int>
     */
    public static function incompleteIds(): array
    {
        $ids = [];

        Place::query()
            ->withPublishCheckData()
            ->chunkById(500, function ($places) use (&$ids) {
                foreach ($places as $place) {
                    if (! self::for($place)->isComplete()) {
                        $ids[] = $place->id;
                    }
                }
            });

        return $ids;
    }

    private static function filled(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    /**
     * كل يوم من الأيام السبعة ليه حالة صالحة، واليوم المفتوح ليه فترة على الأقل وفتراته صحيحة (من ≠ إلى) ومن
     * غير تداخل. الفترة اللي «إلى» فيها أصغر من «من» بتعدّي نص الليل (18:00 لـ 02:00) ومسموحة (03 القسم 4.4).
     *
     * public لإن جدول مواعيد رمضان في الـ form بيتحقق بنفس القاعدة (مش بنسخة تانية منها).
     */
    public static function hoursComplete(mixed $hours): bool
    {
        if (! is_array($hours)) {
            return false;
        }

        foreach (OpeningHours::DAYS as $day) {
            $entry = $hours[$day] ?? null;
            if (! is_array($entry) || ! in_array($entry['state'] ?? null, self::DAY_STATES, true)) {
                return false;
            }

            if ($entry['state'] === 'open' && self::slotProblem($entry['slots'] ?? null) !== null) {
                return false;
            }
        }

        return true;
    }

    /** سبب إن فترات اليوم المفتوح مش صالحة، أو null لو صالحة. القيم مفاتيح بيحوّلها الـ form لرسالة. */
    public const SLOT_EMPTY = 'empty';

    public const SLOT_TOO_MANY = 'too_many';

    public const SLOT_MALFORMED = 'malformed';

    public const SLOT_EQUAL = 'equal';

    public const SLOT_OVERLAP = 'overlap';

    /**
     * فترات يوم مفتوح: فترة أو اتنين، كل فترة «من» و«إلى» صالحين ومختلفين، والفترتين ما يتداخلوش.
     * مكان واحد للقاعدة: `hoursComplete` بيعتمد عليه، والـ form بيستخدم السبب لرسالة بتسمّي المشكلة.
     */
    public static function slotProblem(mixed $slots): ?string
    {
        if (! is_array($slots) || $slots === []) {
            return self::SLOT_EMPTY;
        }

        if (count($slots) > 2) {
            return self::SLOT_TOO_MANY;
        }

        $intervals = [];
        foreach ($slots as $slot) {
            if (! is_array($slot) || count($slot) !== 2 || ! is_string($slot[0] ?? null) || ! is_string($slot[1] ?? null)) {
                return self::SLOT_MALFORMED;
            }

            $from = OpeningHours::toMinutes($slot[0]);
            $to = OpeningHours::toMinutes($slot[1]);
            if ($from === null || $to === null
                || $from >= OpeningHours::MINUTES_PER_DAY
                || $to > OpeningHours::MINUTES_PER_DAY) {
                return self::SLOT_MALFORMED;
            }

            if ($from === $to) {
                return self::SLOT_EQUAL;
            }

            $intervals[] = [$from, $to > $from ? $to : $to + OpeningHours::MINUTES_PER_DAY];
        }

        // الفترتين في نفس اليوم ما يتداخلوش
        if (count($intervals) === 2) {
            [[$aStart, $aEnd], [$bStart, $bEnd]] = $intervals;
            if ($aStart < $bEnd && $bStart < $aEnd) {
                return self::SLOT_OVERLAP;
            }
        }

        return null;
    }
}
