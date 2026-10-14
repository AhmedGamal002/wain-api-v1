<?php

namespace App\Support;

use App\Models\Area;
use App\Models\Attribute;
use App\Models\Category;
use App\Models\City;
use App\Support\Search\SearchNormalizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * سطر واحد من ملف الـ CSV ← أعمدة مكان جاهزة للحفظ، أو أخطاء بتسمّي العمود (A10). نفس قواعد الفورم (الاسمين
 * والأطوال والسعر والموقع جوه مصر والتليفون والروابط والمواعيد) من غير ما نكرّرها: التليفون من `PhoneNumber`،
 * وإنستجرام من `InstagramHandle`، وفترات المواعيد من `PlacePublishCheck::slotProblem`.
 *
 * المسودة بتتحفظ بالإلزامي بس (الاسمين والتصنيف والمنطقة والعنوان والموقع، شروط 1 لـ 4)، فالناقص من شروط النشر
 * (الوصف والخصائص والسعر والمواعيد) مش خطأ هنا: بيظهر في «اكتمال البيانات» في اللوحة.
 *
 * المرجعيات (التصنيف والخاصية والمدينة والمنطقة) بتتحمّل مرة واحدة في الـ constructor وتتطابق بالـ slug أو
 * بالاسم العربي أو الإنجليزي (مطبَّع)، فملف 500 سطر ما بيعملش استعلام لكل سطر.
 */
final class PlaceCsvRowParser
{
    private const MAX_CATEGORIES = 3;

    private const MAX_ALIASES = 10;

    private const MAX_ALIAS_LENGTH = 40;

    private const PRICE_MAX = 10000;

    /** النقطة بعيدة عن مركز المنطقة بأكتر من ده والمبدّلة أقرب من الحد التاني: غالباً العرض والطول متبدّلين. */
    private const SWAP_FAR_METERS = 60_000;

    private const SWAP_NEAR_METERS = 30_000;

    /** @var Collection<int, Category> */
    private Collection $categories;

    /** @var Collection<int, Attribute> */
    private Collection $attributes;

    /** @var Collection<int, City> */
    private Collection $cities;

    /** @var Collection<int, Area> */
    private Collection $areas;

    public function __construct()
    {
        $this->categories = Category::query()->where('is_active', true)->get(['id', 'slug', 'name_ar', 'name_en']);
        $this->attributes = Attribute::query()->where('is_active', true)->get(['id', 'slug', 'name_ar', 'name_en']);
        $this->cities = City::query()->get(['id', 'slug', 'name_ar', 'name_en']);
        $this->areas = Area::query()->get(['id', 'city_id', 'slug', 'name_ar', 'name_en', 'center_lat', 'center_lng']);
    }

    /**
     * @param  array<string, string>  $cells  عمود => قيمة (من `PlaceCsv::read`)
     * @return array{data: array<string, mixed>, errors: list<string>}
     */
    public function parse(array $cells): array
    {
        $errors = [];
        $get = fn (string $column): string => trim($cells[$column] ?? '');
        $fail = function (string $column, string $message) use (&$errors): void {
            $errors[] = "{$column}: {$message}";
        };

        $data = [];

        // الاسمين والعنوان
        foreach (['name_ar' => 'الاسم بالعربي', 'name_en' => 'الاسم بالإنجليزي'] as $column => $label) {
            $data[$column] = $get($column);

            if ($data[$column] === '') {
                $fail($column, "{$label} مطلوب");
            } elseif (mb_strlen($data[$column]) < 2 || mb_strlen($data[$column]) > 120) {
                $fail($column, "{$label} من 2 لـ 120 حرف");
            }
        }

        $data['address_ar'] = $get('address_ar');
        if ($data['address_ar'] === '') {
            $fail('address_ar', 'العنوان بالعربي مطلوب');
        } elseif (mb_strlen($data['address_ar']) > 255) {
            $fail('address_ar', 'العنوان لحد 255 حرف');
        }

        $data['address_en'] = $this->text($get('address_en'), 255, 'address_en', 'العنوان بالإنجليزي', $fail);
        $data['description_ar'] = $this->text($get('description_ar'), 1000, 'description_ar', 'الوصف بالعربي', $fail);
        $data['description_en'] = $this->text($get('description_en'), 1000, 'description_en', 'الوصف بالإنجليزي', $fail);
        $data['internal_notes'] = $this->text($get('internal_notes'), 2000, 'internal_notes', 'الملاحظات الداخلية', $fail);

        $data['search_aliases'] = $this->aliases($get('search_aliases'), $fail);

        // التصنيفات
        [$categoryIds, $primaryId] = $this->categoryColumns($get('categories'), $get('primary_category'), $fail);
        $data['category_ids'] = $categoryIds;
        $data['primary_category_id'] = $primaryId;

        // الخصائص
        $data['attribute_ids'] = $this->attributeIds($get('attributes'), $fail);

        // السعر
        [$data['price_min'], $data['price_max']] = $this->prices($get('price_min'), $get('price_max'), $fail);

        // المدينة والمنطقة والموقع
        [$data['city_id'], $data['area_id']] = $this->place($get('city'), $get('area'), $fail);
        [$data['latitude'], $data['longitude']] = $this->coordinates($get('latitude'), $get('longitude'), $fail);

        $this->checkSwappedCoordinates($data, $fail);

        $data['google_maps_url'] = $this->url($get('google_maps_url'), 500, 'google_maps_url', false, $fail);

        // التواصل
        $data['phone'] = $this->phone($get('phone'), false, 'phone', $fail);
        $data['whatsapp'] = $this->phone($get('whatsapp'), true, 'whatsapp', $fail);
        $data['instagram'] = $this->instagram($get('instagram'), $fail);
        $data['facebook_url'] = $this->url($get('facebook_url'), 255, 'facebook_url', true, $fail);
        $data['website'] = $this->url($get('website'), 255, 'website', true, $fail);
        $data['menu_url'] = $this->url($get('menu_url'), 500, 'menu_url', true, $fail);

        // المواعيد
        $data['opening_hours'] = $this->hours('hours_', $cells, $fail);
        $data['ramadan_hours'] = $this->hours('ramadan_hours_', $cells, $fail);

        return ['data' => $data, 'errors' => $errors];
    }

    /**
     * @param  callable(string, string): void  $fail
     */
    private function text(string $value, int $max, string $column, string $label, callable $fail): ?string
    {
        if ($value === '') {
            return null;
        }

        if (mb_strlen($value) > $max) {
            $fail($column, "{$label} لحد {$max} حرف");
        }

        return $value;
    }

    /**
     * @param  callable(string, string): void  $fail
     * @return list<string>|null
     */
    private function aliases(string $value, callable $fail): ?array
    {
        $aliases = $this->list($value);

        if ($aliases === []) {
            return null;
        }

        if (count($aliases) > self::MAX_ALIASES) {
            $fail('search_aliases', 'لحد '.self::MAX_ALIASES.' كلمات');
        }

        foreach ($aliases as $alias) {
            if (mb_strlen($alias) > self::MAX_ALIAS_LENGTH) {
                $fail('search_aliases', 'كل كلمة لحد '.self::MAX_ALIAS_LENGTH." حرف: «{$alias}»");
            }
        }

        return $aliases;
    }

    /**
     * عناصر قايمة مفصولة بـ `;` (والـ `|` والفاصلة العربية)، من غير فاضي ولا مكرر.
     *
     * @return list<string>
     */
    private function list(string $value): array
    {
        $items = preg_split('/[;|،]/u', $value) ?: [];

        return array_values(array_unique(array_filter(array_map('trim', $items), fn (string $item) => $item !== '')));
    }

    /**
     * @param  callable(string, string): void  $fail
     * @return array{0: list<int>, 1: ?int}
     */
    private function categoryColumns(string $categories, string $primary, callable $fail): array
    {
        $tokens = $this->list($categories);

        if ($tokens === [] && $primary !== '') {
            $tokens = [$primary];
        }

        if ($tokens === []) {
            $fail('categories', 'لازم تصنيف واحد على الأقل');

            return [[], null];
        }

        $ids = [];
        foreach ($tokens as $token) {
            $category = $this->find($this->categories, $token);

            if ($category === null) {
                $fail('categories', "التصنيف «{$token}» مش موجود أو مش ظاهر");

                continue;
            }

            $ids[] = $category->id;
        }

        $ids = array_values(array_unique($ids));

        if (count($ids) > self::MAX_CATEGORIES) {
            $fail('categories', 'أقصى '.self::MAX_CATEGORIES.' تصنيفات');
        }

        if ($primary === '') {
            return [$ids, $ids[0] ?? null];
        }

        $primaryCategory = $this->find($this->categories, $primary);

        if ($primaryCategory === null) {
            $fail('primary_category', "التصنيف الرئيسي «{$primary}» مش موجود أو مش ظاهر");

            return [$ids, null];
        }

        if (! in_array($primaryCategory->id, $ids, true)) {
            $fail('primary_category', 'التصنيف الرئيسي لازم يكون من التصنيفات المكتوبة في categories');

            return [$ids, null];
        }

        return [$ids, $primaryCategory->id];
    }

    /**
     * @param  callable(string, string): void  $fail
     * @return list<int>
     */
    private function attributeIds(string $value, callable $fail): array
    {
        $ids = [];

        foreach ($this->list($value) as $token) {
            $attribute = $this->find($this->attributes, $token);

            if ($attribute === null) {
                $fail('attributes', "الخاصية «{$token}» مش موجودة أو مش ظاهرة");

                continue;
            }

            $ids[] = $attribute->id;
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  callable(string, string): void  $fail
     * @return array{0: ?int, 1: ?int}
     */
    private function prices(string $min, string $max, callable $fail): array
    {
        $parse = function (string $value, string $column) use ($fail): ?int {
            if ($value === '') {
                return null;
            }

            $value = strtr($value, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);

            if (preg_match('/^\d{1,5}(?:\.0+)?$/', $value) !== 1 || (int) $value > self::PRICE_MAX) {
                $fail($column, 'رقم صحيح من 0 لـ '.self::PRICE_MAX);

                return null;
            }

            return (int) $value;
        };

        $priceMin = $parse($min, 'price_min');
        $priceMax = $parse($max, 'price_max');

        if ($priceMin !== null && $priceMax !== null && $priceMax < $priceMin) {
            $fail('price_max', 'السعر «إلى» لازم يكون أكبر من أو يساوي «من»');
        }

        return [$priceMin, $priceMax];
    }

    /**
     * @param  callable(string, string): void  $fail
     * @return array{0: ?int, 1: ?int} city_id وarea_id
     */
    private function place(string $city, string $area, callable $fail): array
    {
        $cityModel = null;

        if ($city !== '') {
            $cityModel = $this->find($this->cities, $city);

            if ($cityModel === null) {
                $fail('city', "المدينة «{$city}» مش موجودة");
            }
        }

        if ($area === '') {
            $fail('area', 'المنطقة مطلوبة');

            return [$cityModel?->id, null];
        }

        $candidates = $cityModel !== null
            ? $this->areas->where('city_id', $cityModel->id)
            : $this->areas;

        $areaModel = $this->find($candidates, $area);

        if ($areaModel === null) {
            // اسم منطقة بيتكرر في مدينتين من غير ما حد حدد المدينة بيرجع «مش موجودة»، فنفرّق
            $fail('area', $city === '' && $this->matches($this->areas, $area) > 1
                ? "المنطقة «{$area}» في أكتر من مدينة، اكتب المدينة في عمود city"
                : "المنطقة «{$area}» مش موجودة".($cityModel !== null ? " في {$cityModel->name_ar}" : ''));

            return [$cityModel?->id, null];
        }

        return [$areaModel->city_id, $areaModel->id];
    }

    /**
     * @param  callable(string, string): void  $fail
     * @return array{0: ?float, 1: ?float}
     */
    private function coordinates(string $lat, string $lng, callable $fail): array
    {
        $parse = function (string $value, string $column, string $label) use ($fail): ?float {
            // فاصلة عشرية («30,0283») من Excel بإعدادات عربي
            $value = preg_replace('/^(-?\d+),(\d+)$/', '$1.$2', trim($value)) ?? $value;

            if ($value === '') {
                $fail($column, "{$label} مطلوب");

                return null;
            }

            if (! is_numeric($value)) {
                $fail($column, "{$label} لازم يكون رقم بنقطة عشرية");

                return null;
            }

            return (float) $value;
        };

        $latitude = $parse($lat, 'latitude', 'خط العرض');
        $longitude = $parse($lng, 'longitude', 'خط الطول');

        if ($latitude !== null && $longitude !== null && ! PlaceLocation::isInsideEgypt($latitude, $longitude)) {
            $fail('latitude', 'الإحداثيات برّه مصر. اتأكد إن العرض (حوالي 30) والطول (حوالي 31) مش متبدّلين');
            $latitude = $longitude = null;
        }

        return [$latitude, $longitude];
    }

    /**
     * خط العرض والطول جوه حدود مصر الاتنين لما يتبدّلوا (30.03 و31.49)، فالحدود ما بتمسكهمش. لو النقطة بعيدة عن
     * مركز المنطقة والمبدّلة قريبة منه يبقى غالباً اتبدّلوا. مركز المنطقة تقريبي، فبنقارن بس لما الفرق واضح.
     *
     * @param  array<string, mixed>  $data
     * @param  callable(string, string): void  $fail
     */
    private function checkSwappedCoordinates(array $data, callable $fail): void
    {
        $area = $data['area_id'] === null ? null : $this->areas->firstWhere('id', $data['area_id']);
        $lat = $data['latitude'];
        $lng = $data['longitude'];

        if ($area === null || $lat === null || $lng === null || ! PlaceLocation::isInsideEgypt($lng, $lat)) {
            return;
        }

        $centerLat = (float) $area->center_lat;
        $centerLng = (float) $area->center_lng;

        $asWritten = PlaceLocation::haversineMeters($lat, $lng, $centerLat, $centerLng);
        $swapped = PlaceLocation::haversineMeters($lng, $lat, $centerLat, $centerLng);

        if ($asWritten > self::SWAP_FAR_METERS && $swapped < self::SWAP_NEAR_METERS) {
            $fail('latitude', 'العرض والطول شكلهم متبدّلين (الأول لازم يكون خط العرض، حوالي '.round($centerLat, 2).' للمنطقة دي، والتاني الطول حوالي '.round($centerLng, 2).')');
        }
    }

    /**
     * @param  callable(string, string): void  $fail
     */
    private function url(string $value, int $max, string $column, bool $httpsOnly, callable $fail): ?string
    {
        if ($value === '') {
            return null;
        }

        $valid = filter_var($value, FILTER_VALIDATE_URL) !== false
            && preg_match($httpsOnly ? '~^https://~i' : '~^https?://~i', $value) === 1;

        if (! $valid) {
            $fail($column, $httpsOnly ? 'رابط لازم يبدأ بـ https://' : 'رابط صالح (http أو https)');
        } elseif (mb_strlen($value) > $max) {
            $fail($column, "الرابط لحد {$max} حرف");
        }

        return $value;
    }

    /**
     * @param  callable(string, string): void  $fail
     */
    private function phone(string $value, bool $mobileOnly, string $column, callable $fail): ?string
    {
        if ($value === '') {
            return null;
        }

        $normalized = PhoneNumber::normalize($value);

        if ($normalized === null) {
            $fail($column, "رقم مصري غير صالح «{$value}»: موبايل 01… أو أرضي بكود المحافظة أو خط ساخن من 5 أرقام");

            return null;
        }

        if ($mobileOnly && ! PhoneNumber::isMobile($normalized)) {
            $fail($column, 'الواتساب لازم يكون رقم موبايل مصري');

            return null;
        }

        return $normalized;
    }

    /**
     * @param  callable(string, string): void  $fail
     */
    private function instagram(string $value, callable $fail): ?string
    {
        $handle = InstagramHandle::from($value);

        if ($handle !== null && ! InstagramHandle::isValid($handle)) {
            $fail('instagram', 'اسم الحساب حروف وأرقام ونقطة و_ بس، لحد 30');

            return null;
        }

        return $handle;
    }

    /**
     * مواعيد 7 أيام (`hours_sat` … ). اليوم الفاضي بيتشال (يظهر ناقص في شرط «المواعيد»)، ولو كلهم فاضيين null.
     *
     * @param  array<string, string>  $cells
     * @param  callable(string, string): void  $fail
     * @return array<string, array<string, mixed>>|null
     */
    private function hours(string $prefix, array $cells, callable $fail): ?array
    {
        $hours = [];

        foreach (OpeningHours::DAYS as $day) {
            $column = $prefix.$day;
            $result = PlaceCsvHours::parseDay($cells[$column] ?? '');

            if ($result['error'] !== null) {
                $fail($column, $result['error']);

                continue;
            }

            if ($result['value'] !== null) {
                $hours[$day] = $result['value'];
            }
        }

        return $hours === [] ? null : $hours;
    }

    /**
     * التطابق بالـ slug الأول، وبعده بالاسم العربي أو الإنجليزي (مطبَّع). null لو مفيش أو لو الاسم بيطابق أكتر
     * من واحد (غموض: بنطلب الـ slug).
     *
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  Collection<int, T>  $items
     * @return T|null
     */
    private function find(Collection $items, string $token): mixed
    {
        $slug = mb_strtolower(trim($token));

        $bySlug = $items->first(fn ($item) => mb_strtolower((string) $item->slug) === $slug);
        if ($bySlug !== null) {
            return $bySlug;
        }

        $matches = $this->nameMatches($items, $token);

        return $matches->count() === 1 ? $matches->first() : null;
    }

    private function matches(Collection $items, string $token): int
    {
        return $this->nameMatches($items, $token)->count();
    }

    /**
     * @return Collection<int, Model>
     */
    private function nameMatches(Collection $items, string $token): Collection
    {
        $needle = SearchNormalizer::normalize($token);

        if ($needle === '') {
            return new Collection;
        }

        return $items->filter(fn ($item) => SearchNormalizer::normalize($item->name_ar) === $needle
            || SearchNormalizer::normalize($item->name_en) === $needle)->values();
    }
}
