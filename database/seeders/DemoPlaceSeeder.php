<?php

namespace Database\Seeders;

use App\Support\PlaceLocation;
use App\Support\PriceRange;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * 30 مكان تجريبي للتطوير (06 القسم 6: `local` فقط، وما بيروحش production أبداً).
 *
 * البيانات خيالية. التوزيع:
 *  - 26 منشور، ومعاهم حالات متعمّدة عشان الـ API يتأكد إنه ما بيسرّبهاش: 1 مخفي، 1 مغلق نهائياً،
 *    1 مسودة (ناقصة)، 1 قيد المراجعة.
 *  - باقات: premium × 2 و featured × 4 (واحدة بتنتهي خلال 5 أيام) والباقي free.
 *  - المواعيد: عادي، بعد نص الليل، فترتين، 24 ساعة، مقفول يوم، ومواعيد رمضان لـ 3 أماكن.
 *  - تقييمات: أقل من 3 («جديد»)، وصفر، وعالي. آخر تأكيد: حديث وأقدم من 90 يوم.
 *
 * كل مكان منشور بيحقق شروط النشر التسعة (03 القسم 4.3)، ومعاه غلاف `ready`.
 *
 * الصور روابط placeholder من picsum.photos بدل ملفات على R2 (ما فيش معالجة صور لحد أسبوع 9)،
 * فالـ resource بتاع الـ API لازم يمرّر الرابط المطلق زي ما هو.
 *
 * بيستخدم DB::table (مفيش Place model لسه)، فـ search_text و place_hours بيتبنوا بعدين من الـ
 * listeners/الـ command بتوع معلوم. إعادة التشغيل بتتخطى الأماكن الموجودة (بالـ slug).
 */
class DemoPlaceSeeder extends Seeder
{
    public const DEMO_USER_EMAIL = 'demo-seeder@wain.invalid';

    private const DAYS = ['sat', 'sun', 'mon', 'tue', 'wed', 'thu', 'fri'];

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('DemoPlaceSeeder بيشتغل في local أو testing بس.');
        }

        $this->call([CategorySeeder::class, CityAreaSeeder::class, AttributeSeeder::class]);

        $categories = DB::table('categories')->pluck('id', 'slug');
        $attributes = DB::table('attributes')->pluck('id', 'slug');
        $cityId = DB::table('cities')->where('slug', 'cairo')->value('id');
        $areaId = DB::table('areas')->where('slug', 'new-cairo')->value('id');
        $userId = $this->demoUserId();

        foreach ($this->places() as $index => $place) {
            if (DB::table('places')->where('slug', $place['slug'])->exists()) {
                continue;
            }

            $this->createPlace($index + 1, $place, $categories, $attributes, $cityId, $areaId, $userId);
        }
    }

    private function demoUserId(): int
    {
        DB::table('users')->insertOrIgnore([
            'name' => 'Demo Seeder',
            'email' => self::DEMO_USER_EMAIL,
            'locale' => 'ar',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) DB::table('users')->where('email', self::DEMO_USER_EMAIL)->value('id');
    }

    /**
     * @param  array<string, mixed>  $p
     */
    private function createPlace(int $n, array $p, $categories, $attributes, int $cityId, int $areaId, int $userId): void
    {
        $status = $p['status'] ?? 'active';
        $tier = $p['tier'] ?? 'free';
        $paid = $tier !== 'free';
        $published = in_array($status, ['active', 'hidden', 'closed'], true);
        $publishedAt = $published ? now()->subDays($p['published']) : null;
        [$priceMin, $priceMax] = $p['price'];
        [$ratingAvg, $ratingCount] = $p['rating'] ?? [0, 0];
        $hasContact = $p['contact'] ?? false;

        $placeId = DB::table('places')->insertGetId([
            'slug' => $p['slug'],
            'name_ar' => $p['name_ar'],
            'name_en' => $p['name_en'],
            'search_aliases' => isset($p['aliases']) ? json_encode($p['aliases'], JSON_UNESCAPED_UNICODE) : null,
            'description_ar' => $p['desc'],
            'primary_category_id' => $categories[$p['category']],
            'city_id' => $cityId,
            'area_id' => $areaId,
            'address_ar' => $p['address'],
            'latitude' => $p['lat'],
            'longitude' => $p['lng'],
            'location' => PlaceLocation::point($p['lat'], $p['lng']),
            'google_maps_url' => "https://www.google.com/maps/search/?api=1&query={$p['lat']},{$p['lng']}",
            // أرقام وهمية بصيغة دولية: +20 ثم 10 أرقام (موبايل 1XXXXXXXXX)
            'phone' => $hasContact ? sprintf('+20100000%04d', $n) : null,
            'whatsapp' => $hasContact && $n % 2 === 1 ? sprintf('+20100000%04d', $n) : null,
            'instagram' => $hasContact ? sprintf('wain.demo.%02d', $n) : null,
            'price_min' => $priceMin,
            'price_max' => $priceMax,
            'price_range' => PriceRange::forMaxPrice($priceMax),
            'opening_hours' => json_encode($this->hours($p['hours']), JSON_UNESCAPED_UNICODE),
            'ramadan_hours' => ($p['ramadan'] ?? false) ? json_encode($this->hours('ramadan'), JSON_UNESCAPED_UNICODE) : null,
            'status' => $status,
            'status_reason' => match ($status) {
                'hidden' => 'مخفي مؤقتاً (بيانات تجريبية)',
                'closed' => 'اتقفل نهائياً (بيانات تجريبية)',
                default => null,
            },
            'published_at' => $publishedAt,
            'closed_at' => $status === 'closed' ? now()->subDays(20)->toDateString() : null,
            'listing_tier' => $tier,
            'tier_rank' => ['premium' => 1, 'featured' => 2, 'free' => 3][$tier],
            'tier_starts_at' => $paid ? now()->subDays(10) : null,
            'tier_ends_at' => $paid ? now()->addDays($p['tier_days'] ?? 80) : null,
            'verified_at' => isset($p['verified']) ? now()->subDays($p['verified']) : null,
            'rating_avg' => $ratingAvg,
            'rating_count' => $ratingCount,
            'created_by' => $userId,
            'updated_by' => $userId,
            'created_at' => now()->subDays(($p['published'] ?? 3) + 7),
            'updated_at' => now()->subDays($p['verified'] ?? 1),
        ]);

        $categoryIds = collect([$p['category'], ...($p['also'] ?? [])])->map(fn ($slug) => $categories[$slug]);
        DB::table('place_category')->insert($categoryIds->map(fn ($id) => ['place_id' => $placeId, 'category_id' => $id])->all());

        DB::table('attribute_place')->insert(
            collect($p['attrs'])->map(fn ($slug) => ['place_id' => $placeId, 'attribute_id' => $attributes[$slug]])->all(),
        );

        $this->createPhotos($placeId, $p, $n, $userId);
    }

    /**
     * @param  array<string, mixed>  $p
     */
    private function createPhotos(int $placeId, array $p, int $n, int $userId): void
    {
        // المسودة من غير غلاف (عشان شرط النشر 9 يفشل فيها)
        if (($p['status'] ?? 'active') === 'draft') {
            return;
        }

        $coverId = null;
        $gallery = $n <= 10 ? 3 : 1;

        for ($i = 0; $i < $gallery; $i++) {
            $id = $this->insertPhoto($placeId, $p['slug'], 'gallery', $i, $userId);
            $coverId ??= $id;
        }

        // صور منيو للمطاعم
        if ($p['category'] === 'restaurants') {
            for ($i = 0; $i < 2; $i++) {
                $this->insertPhoto($placeId, $p['slug'], 'menu', $i, $userId);
            }
        }

        DB::table('places')->where('id', $placeId)->update(['cover_photo_id' => $coverId]);
    }

    private function insertPhoto(int $placeId, string $slug, string $type, int $order, int $userId): int
    {
        $seed = "{$slug}-{$type}-{$order}";
        $url = fn (int $width) => "https://picsum.photos/seed/{$seed}/{$width}/".intdiv($width * 2, 3);

        return DB::table('place_photos')->insertGetId([
            'place_id' => $placeId,
            'type' => $type,
            'path' => "demo/{$slug}/{$type}-{$order}.jpg",
            'variants' => json_encode(['thumb' => $url(240), 'medium' => $url(800), 'large' => $url(1600)]),
            'width' => 1200,
            'height' => 800,
            'sort_order' => $order,
            'status' => 'ready',
            'uploaded_by' => $userId,
        ]);
    }

    /**
     * أنماط المواعيد (الشكل في 03 القسم 4.4). «إلى» أصغر من «من» = بيقفل تاني يوم.
     *
     * @return array<string, array<string, mixed>>
     */
    private function hours(string $pattern): array
    {
        $open = fn (array ...$slots) => ['state' => 'open', 'slots' => $slots];
        $everyDay = fn (array $day) => array_fill_keys(self::DAYS, $day);

        return match ($pattern) {
            // بعد نص الليل كل يوم
            'cafe' => $everyDay($open(['09:00', '01:00'])),
            'restaurant' => $everyDay($open(['12:00', '00:00'])),
            // فترتين في اليوم، والجمعة مختلفة
            'restaurant_split' => [...$everyDay($open(['12:00', '16:00'], ['19:00', '00:30'])), 'fri' => $open(['13:00', '01:00'])],
            // مقفول يوم الاتنين
            'evening' => [...$everyDay($open(['17:00', '02:00'])), 'mon' => ['state' => 'closed']],
            'late' => $everyDay($open(['14:00', '03:00'])),
            'fastfood' => $everyDay($open(['10:00', '03:00'])),
            '24h' => $everyDay(['state' => '24h']),
            'park' => $everyDay($open(['06:00', '22:00'])),
            // مقفول الجمعة
            'tourist' => [...$everyDay($open(['09:00', '17:00'])), 'fri' => ['state' => 'closed']],
            'entertainment' => [...$everyDay($open(['14:00', '00:00'])), 'thu' => $open(['12:00', '02:00']), 'fri' => $open(['12:00', '02:00'])],
            // من الفطار للسحور
            'ramadan' => $everyDay($open(['20:00', '04:00'])),
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function places(): array
    {
        return [
            // ---- كافيهات (8) ----
            [
                'slug' => 'el-mahatta-coffee', 'name_ar' => 'قهوة المحطة', 'name_en' => 'El Mahatta Coffee',
                'category' => 'cafes', 'also' => ['desserts'], 'lat' => 30.0283, 'lng' => 31.4913,
                'address' => 'شارع التسعين الشمالي، التجمع الخامس', 'price' => [60, 140], 'tier' => 'featured',
                'rating' => [4.5, 120], 'hours' => 'cafe', 'ramadan' => true, 'contact' => true,
                'attrs' => ['wifi', 'power-outlets', 'quiet', 'study-work'], 'published' => 120, 'verified' => 4,
                'aliases' => ['mahatta', 'el mahta', 'قهوه المحطه'],
                'desc' => 'كافيه هادي بإضاءة دافية ومساحات شغل واسعة، قهوة مختصة وحلويات بيتي. مناسب للمذاكرة والشغل بالساعات.',
            ],
            [
                'slug' => 'bayt-el-bunn', 'name_ar' => 'بيت البن', 'name_en' => 'Bayt El Bunn',
                'category' => 'cafes', 'lat' => 30.0196, 'lng' => 31.4655,
                'address' => 'الحي الثالث، التجمع الخامس', 'price' => [50, 120],
                'rating' => [4.2, 64], 'hours' => 'cafe', 'contact' => true,
                'attrs' => ['wifi', 'outdoor', 'card-payment'], 'published' => 90, 'verified' => 12,
                'desc' => 'محمصة وكافيه بيحمّص البن قدامك، مشروبات باردة وساخنة وجلسة خارجية في الجنينة.',
            ],
            [
                'slug' => 'coffee-lab-5th', 'name_ar' => 'كوفي لاب', 'name_en' => 'Coffee Lab 5th',
                'category' => 'cafes', 'lat' => 30.0412, 'lng' => 31.4720,
                'address' => 'شارع التسعين الجنوبي، التجمع الخامس', 'price' => [70, 160],
                'rating' => [4.7, 31], 'hours' => 'cafe',
                'attrs' => ['wifi', 'quiet', 'power-outlets', 'card-payment'], 'published' => 70, 'verified' => 100,
                'desc' => 'مكان لعشاق القهوة المختصة، باريستا بيشرحلك الفرق بين المحاصيل وبيعملك الـ V60 على مزاجك.',
            ],
            [
                'slug' => 'rukn-el-hodoo', 'name_ar' => 'ركن الهدوء', 'name_en' => 'Quiet Corner',
                'category' => 'cafes', 'lat' => 30.0087, 'lng' => 31.4460,
                'address' => 'الحي الأول، التجمع الخامس', 'price' => [45, 100],
                'rating' => [4.0, 12], 'hours' => 'cafe',
                'attrs' => ['quiet', 'corner-seating', 'ac', 'dates'], 'published' => 45, 'verified' => 20,
                'desc' => 'ركن صغير ومريح بعيد عن الزحمة، ركنات مريحة وموسيقى هادية، مناسب للقاءات الصغيرة والقراءة.',
            ],
            [
                'slug' => 'studies-cafe', 'name_ar' => 'ستاديز كافيه', 'name_en' => 'Studies Cafe',
                'category' => 'cafes', 'lat' => 30.0231, 'lng' => 31.5008,
                'address' => 'بجوار الجامعات، التجمع الخامس', 'price' => [40, 90], 'contact' => true,
                'rating' => [4.4, 210], 'hours' => 'cafe',
                'attrs' => ['wifi', 'power-outlets', 'quiet', 'study-work', 'ac'], 'published' => 150, 'verified' => 3,
                'desc' => 'مصمم للمذاكرة: طاولات كبيرة وفيش كهربا عند كل كرسي وإنترنت سريع، وفيه ركن للمجموعات.',
            ],
            [
                'slug' => 'habbet-kahwa', 'name_ar' => 'حبة قهوة', 'name_en' => 'Habbet Kahwa',
                'category' => 'cafes', 'lat' => 30.0334, 'lng' => 31.4402,
                'address' => 'النرجس، التجمع الخامس', 'price' => [35, 80],
                'rating' => [3.8, 45], 'hours' => 'cafe',
                'attrs' => ['family', 'kids', 'outdoor', 'shisha'], 'published' => 33, 'verified' => 9,
                'desc' => 'كافيه عائلي بسيط بأسعار معقولة، قعدة خارجية ومنطقة أطفال صغيرة وشيشة في الجزء الخارجي.',
            ],
            [
                'slug' => 'nasma-cafe', 'name_ar' => 'نسمة كافيه', 'name_en' => 'Nasma Cafe',
                'category' => 'cafes', 'lat' => 30.0460, 'lng' => 31.4885,
                'address' => 'اللوتس، التجمع الخامس', 'price' => [55, 130],
                'rating' => [0, 0], 'hours' => 'cafe',
                'attrs' => ['outdoor', 'view', 'dates'], 'published' => 6, 'verified' => 2,
                'desc' => 'جلسات خارجية على الحديقة ونسمة هوا حلوة بالليل، مشروبات منعشة وسندوتشات خفيفة.',
            ],
            [
                'slug' => 'qahwa-w-ketab', 'name_ar' => 'قهوة وكتاب', 'name_en' => 'Qahwa & Ketab',
                'category' => 'cafes', 'lat' => 30.0121, 'lng' => 31.4790,
                'address' => 'الياسمين، التجمع الخامس', 'price' => [50, 110],
                'rating' => [4.6, 2], 'hours' => 'cafe',
                'attrs' => ['quiet', 'wifi', 'study-work'], 'published' => 14, 'verified' => 1,
                'desc' => 'مكتبة صغيرة جوه الكافيه، تقدر تقرا وتشرب قهوتك براحتك، وبيعملوا أمسيات قراءة كل خميس.',
            ],

            // ---- مطاعم (7) ----
            [
                'slug' => 'al-sham-al-aseel', 'name_ar' => 'مطعم الشام الأصيل', 'name_en' => 'Al Sham Al Aseel',
                'category' => 'restaurants', 'lat' => 30.0301, 'lng' => 31.4780,
                'address' => 'شارع التسعين الشمالي، التجمع الخامس', 'price' => [250, 480], 'tier' => 'premium',
                'rating' => [4.8, 310], 'hours' => 'restaurant', 'ramadan' => true, 'contact' => true,
                'attrs' => ['family', 'large-groups', 'reservations', 'card-payment', 'ac', 'kids'], 'published' => 200, 'verified' => 6,
                'desc' => 'مأكولات شامية على أصولها: مشاوي ومقبلات ومعجنات، وقعدات عيلية واسعة وخدمة محترمة.',
            ],
            [
                'slug' => 'mashwayat-el-reef', 'name_ar' => 'مشويات الريف', 'name_en' => 'Mashwayat El Reef',
                'category' => 'restaurants', 'lat' => 30.0150, 'lng' => 31.4575,
                'address' => 'الحي الخامس، التجمع الخامس', 'price' => [150, 300], 'tier' => 'featured',
                'rating' => [4.3, 180], 'hours' => 'restaurant_split', 'contact' => true,
                'attrs' => ['family', 'large-groups', 'outdoor', 'delivery'], 'published' => 110, 'verified' => 15,
                'desc' => 'مشويات على الفحم وأكل ريفي مصري، أطباق كبيرة تكفي الجروب وأسعار مناسبة.',
            ],
            [
                'slug' => 'pizza-el-qamar', 'name_ar' => 'بيتزا القمر', 'name_en' => 'Pizza El Qamar',
                'category' => 'restaurants', 'lat' => 30.0375, 'lng' => 31.4560,
                'address' => 'الحي الثاني، التجمع الخامس', 'price' => [120, 260],
                'rating' => [4.1, 95], 'hours' => 'restaurant',
                'attrs' => ['family', 'dates', 'delivery', 'card-payment'], 'published' => 80, 'verified' => 30,
                'desc' => 'بيتزا نابوليتانية على الحطب وعجينة بتتخمر 48 ساعة، ومعاها باستا وسلطات.',
            ],
            [
                'slug' => 'el-bahr-el-azraq', 'name_ar' => 'البحر الأزرق', 'name_en' => 'El Bahr El Azraq',
                'category' => 'restaurants', 'lat' => 30.0053, 'lng' => 31.4930,
                'address' => 'الحي السابع، التجمع الخامس', 'price' => [300, 650], 'contact' => true,
                'rating' => [4.5, 72], 'hours' => 'restaurant',
                'attrs' => ['reservations', 'card-payment', 'view', 'family'], 'published' => 55, 'verified' => 8,
                'desc' => 'مأكولات بحرية طازجة يوم بيوم، سمك مشوي وجمبري وكالاماري، مع تشكيلة سلطات.',
            ],
            [
                'slug' => 'koshary-el-hara', 'name_ar' => 'كشري الحارة', 'name_en' => 'Koshary El Hara',
                'category' => 'restaurants', 'also' => ['fast-food'], 'lat' => 30.0218, 'lng' => 31.4390,
                'address' => 'الحي الرابع، التجمع الخامس', 'price' => [25, 70],
                'rating' => [4.0, 260], 'hours' => 'fastfood',
                'attrs' => ['delivery', 'family', 'card-payment', 'large-groups'], 'published' => 300, 'verified' => 40,
                'aliases' => ['koshari', 'koshary', 'كشرى'],
                'desc' => 'كشري وأكل مصري شعبي بنظافة ومستوى مطاعم، حواوشي وطواجن وحلويات تقليدية.',
            ],
            [
                'slug' => 'steak-house-5', 'name_ar' => 'ستيك هاوس 5', 'name_en' => 'Steak House 5',
                'category' => 'restaurants', 'lat' => 30.0440, 'lng' => 31.5050,
                'address' => 'التسعين الشمالي، التجمع الخامس', 'price' => [350, 700],
                'rating' => [4.4, 58], 'hours' => 'restaurant',
                'attrs' => ['reservations', 'card-payment', 'dates', 'romantic', 'ac'], 'published' => 40, 'verified' => 7,
                'desc' => 'ستيك وبرجر لحم مفروم يومياً، تقديم أنيق وبطاطس بتتعمل في المكان، وفيه قاعة حفلات صغيرة.',
            ],
            [
                'slug' => 'matbakh-mama', 'name_ar' => 'مطبخ ماما', 'name_en' => 'Matbakh Mama',
                'category' => 'restaurants', 'lat' => 30.0095, 'lng' => 31.5100,
                'address' => 'الحي الثامن، التجمع الخامس', 'price' => [80, 170],
                'rating' => [3.9, 33], 'hours' => 'restaurant',
                'attrs' => ['family', 'delivery', 'kids', 'card-payment'], 'published' => 25, 'verified' => 100,
                'desc' => 'أكل بيتي مصري زي بتاع الست الوالدة: محشي ومكرونة بالبشاميل وملوخية، والتوصيل متاح.',
            ],

            // ---- روف (4) ----
            [
                'slug' => 'sama-roof', 'name_ar' => 'سما روف', 'name_en' => 'Sama Roof',
                'category' => 'rooftops', 'also' => ['restaurants'], 'lat' => 30.0305, 'lng' => 31.4960,
                'address' => 'شارع التسعين الشمالي، التجمع الخامس', 'price' => [400, 900], 'tier' => 'premium',
                'rating' => [4.6, 140], 'hours' => 'evening', 'ramadan' => true, 'contact' => true,
                'attrs' => ['view', 'romantic', 'live-music', 'reservations', 'card-payment', 'dates'], 'published' => 130, 'verified' => 5,
                'desc' => 'روف بإطلالة على المدينة وجلسات خاصة للغروب، موسيقى لايف في نص الأسبوع وقائمة مشروبات متنوعة.',
            ],
            [
                'slug' => 'layali-el-qamar-roof', 'name_ar' => 'ليالي القمر روف', 'name_en' => 'Layali El Qamar Roof',
                'category' => 'rooftops', 'lat' => 30.0260, 'lng' => 31.5030,
                'address' => 'اللوتس، التجمع الخامس', 'price' => [200, 450], 'tier' => 'featured', 'tier_days' => 5,
                'rating' => [4.2, 88], 'hours' => 'evening', 'contact' => true,
                'attrs' => ['shisha', 'view', 'lively', 'large-groups', 'live-music'], 'published' => 100, 'verified' => 10,
                'desc' => 'روف لعشاق السهر، شيشة وموسيقى وإطلالة مفتوحة، وحجز الطاولات مفضّل في الويك إند.',
            ],
            [
                'slug' => 'roof-el-ganeina', 'name_ar' => 'روف الجنينة', 'name_en' => 'Roof El Ganeina',
                'category' => 'rooftops', 'lat' => 30.0138, 'lng' => 31.4695,
                'address' => 'الحي الثالث، التجمع الخامس', 'price' => [150, 320],
                'rating' => [3.7, 26], 'hours' => 'evening',
                'attrs' => ['outdoor', 'quiet', 'romantic', 'dates'], 'published' => 35, 'verified' => 22,
                'desc' => 'روف مفتوح بنباتات وإضاءة هادية، مناسب للقاءات الصغيرة والمواعيد الرومانسية.',
            ],
            [
                'slug' => 'talateen-roof', 'name_ar' => 'ثلاثين روف', 'name_en' => 'Talateen Roof',
                'category' => 'rooftops', 'also' => ['cafes'], 'lat' => 30.0485, 'lng' => 31.4640,
                'address' => 'مول التجمع، التجمع الخامس', 'price' => [180, 420],
                'rating' => [4.0, 19], 'hours' => 'evening',
                'attrs' => ['view', 'wifi', 'card-payment'], 'published' => 20, 'verified' => 4,
                'desc' => 'روف في الدور الثلاثين بفيو بانورامي، قهوة ومشروبات وأكل خفيف، والأسعار فوق المتوسط.',
            ],

            // ---- حلويات (3) ----
            [
                'slug' => 'halawiyat-el-delta', 'name_ar' => 'حلويات الدلتا', 'name_en' => 'Halawiyat El Delta',
                'category' => 'desserts', 'lat' => 30.0270, 'lng' => 31.4520,
                'address' => 'الحي الأول، التجمع الخامس', 'price' => [40, 110], 'tier' => 'featured', 'contact' => true,
                'rating' => [4.6, 205], 'hours' => 'late',
                'attrs' => ['delivery', 'family', 'card-payment'], 'published' => 250, 'verified' => 2,
                'desc' => 'حلويات شرقية وغربية طازجة يومياً: كنافة وبسبوسة وتشيز كيك وآيس كريم محلي الصنع.',
            ],
            [
                'slug' => 'cake-beity', 'name_ar' => 'كيك بيتي', 'name_en' => 'Cake Beity',
                'category' => 'desserts', 'also' => ['cafes'], 'lat' => 30.0185, 'lng' => 31.4850,
                'address' => 'الياسمين، التجمع الخامس', 'price' => [60, 150],
                'rating' => [4.3, 41], 'hours' => 'cafe',
                'attrs' => ['delivery', 'corner-seating', 'card-payment'], 'published' => 50, 'verified' => 70,
                'desc' => 'تورتات وكب كيك بتصميمات حسب الطلب، وركن صغير للقعدة وقهوة سريعة.',
            ],
            [
                'slug' => 'chocolat-house', 'name_ar' => 'شوكولا هاوس', 'name_en' => 'Chocolat House',
                'category' => 'desserts', 'lat' => 30.0390, 'lng' => 31.4960,
                'address' => 'الحي السادس، التجمع الخامس', 'price' => [90, 200], 'status' => 'pending',
                'hours' => 'late',
                'attrs' => ['delivery', 'card-payment', 'dates'], 'published' => 3,
                'desc' => 'كل حاجة بالشوكولاتة: فوندو ومشروبات ساخنة وهدايا جاهزة للمناسبات والأعياد.',
            ],

            // ---- وجبات سريعة (3) ----
            [
                'slug' => 'burger-el-hara', 'name_ar' => 'برجر الحارة', 'name_en' => 'Burger El Hara',
                'category' => 'fast-food', 'lat' => 30.0225, 'lng' => 31.4745,
                'address' => 'الحي الثاني، التجمع الخامس', 'price' => [70, 150],
                'rating' => [4.2, 330], 'hours' => 'fastfood',
                'attrs' => ['delivery', 'card-payment', 'large-groups'], 'published' => 180, 'verified' => 18,
                'desc' => 'برجر لحم محلي الصنع وصوصات خاصة، وجبات اقتصادية مع بطاطس وعروض للطلبة.',
            ],
            [
                'slug' => 'shawarma-el-leil', 'name_ar' => 'شاورما الليل', 'name_en' => 'Shawarma El Leil',
                'category' => 'fast-food', 'lat' => 30.0070, 'lng' => 31.4420,
                'address' => 'الحي الرابع، التجمع الخامس', 'price' => [40, 90], 'contact' => true,
                'rating' => [3.9, 150], 'hours' => '24h',
                'attrs' => ['delivery', 'card-payment', 'family'], 'published' => 160, 'verified' => 33,
                'desc' => 'شاورما فراخ ولحمة بالتوم والمخلل، شغّالين 24 ساعة وتوصيل سريع للتجمع.',
            ],
            [
                'slug' => 'fries-stop', 'name_ar' => 'فرايز ستوب', 'name_en' => 'Fries Stop',
                'category' => 'fast-food', 'lat' => 30.0340, 'lng' => 31.5090,
                'address' => 'الحي التاسع، التجمع الخامس', 'price' => [30, 60], 'status' => 'hidden',
                'rating' => [3.1, 8], 'hours' => 'fastfood',
                'attrs' => ['delivery', 'card-payment', 'large-groups'], 'published' => 90, 'verified' => 60,
                'desc' => 'ساندوتشات ووجبات خفيفة سريعة، مكان صغير للاستلام والتوصيل فقط وبأسعار رمزية.',
            ],

            // ---- حدائق (2) ----
            [
                'slug' => 'narges-park', 'name_ar' => 'حديقة النرجس', 'name_en' => 'Narges Park',
                'category' => 'parks', 'lat' => 30.0360, 'lng' => 31.4430,
                'address' => 'النرجس، التجمع الخامس', 'price' => [0, 30],
                'rating' => [4.4, 390], 'hours' => 'park',
                'attrs' => ['outdoor', 'family', 'kids', 'accessible'], 'published' => 360, 'verified' => 25,
                'desc' => 'حديقة واسعة وممشى وملاعب أطفال ومساحات خضرا مناسبة للعيلة والبيكنيك.',
            ],
            [
                'slug' => 'old-lotus-park', 'name_ar' => 'حديقة اللوتس القديمة', 'name_en' => 'Old Lotus Park',
                'category' => 'parks', 'lat' => 30.0410, 'lng' => 31.5120,
                'address' => 'اللوتس، التجمع الخامس', 'price' => [0, 20], 'status' => 'closed',
                'rating' => [3.6, 12], 'hours' => 'park',
                'attrs' => ['outdoor', 'family', 'kids'], 'published' => 400, 'verified' => 150,
                'desc' => 'حديقة اتقفلت نهائياً بعد إعادة تخطيط المنطقة، موجودة هنا لتجربة حالة المكان المغلق.',
            ],

            // ---- أماكن سياحية (1) ----
            [
                'slug' => 'heritage-museum', 'name_ar' => 'متحف التراث', 'name_en' => 'Heritage Museum',
                'category' => 'tourist-spots', 'lat' => 30.0500, 'lng' => 31.4680,
                'address' => 'قريب من الحي الأول، التجمع الخامس', 'price' => [50, 120], 'contact' => true,
                'rating' => [4.5, 22], 'hours' => 'tourist',
                'attrs' => ['family', 'kids', 'accessible', 'ac', 'card-payment'], 'published' => 75, 'verified' => 16,
                'desc' => 'متحف صغير بيعرض تراث وصناعات يدوية مصرية، وفيه ورش للأطفال في الإجازات.',
            ],

            // ---- ترفيه (2) ----
            [
                'slug' => 'alam-el-alaab', 'name_ar' => 'عالم الألعاب', 'name_en' => 'Alam El Alaab',
                'category' => 'entertainment', 'lat' => 30.0430, 'lng' => 31.4300,
                'address' => 'مول التجمع، التجمع الخامس', 'price' => [100, 250],
                'rating' => [4.1, 77], 'hours' => 'entertainment',
                'attrs' => ['family', 'kids', 'large-groups', 'ac', 'card-payment'], 'published' => 85, 'verified' => 11,
                'desc' => 'ألعاب فيديو وبولينج وطاولات بلياردو، مناسب للأصدقاء والجروبات وأعياد الميلاد.',
            ],
            [
                'slug' => 'karting-el-malaeb', 'name_ar' => 'كارتنج الملاعب', 'name_en' => 'Karting El Malaeb',
                'category' => 'entertainment', 'lat' => 30.0520, 'lng' => 31.5150,
                'address' => 'مجاورة اللوتس، التجمع الخامس', 'price' => [150, 300], 'status' => 'draft',
                'hours' => 'entertainment',
                'attrs' => ['outdoor', 'large-groups'], 'published' => 1,
                'desc' => 'مضمار كارتنج.',
            ],
        ];
    }
}
