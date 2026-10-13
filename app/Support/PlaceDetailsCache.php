<?php

namespace App\Support;

use App\Models\Area;
use App\Models\Attribute;
use App\Models\Category;
use App\Models\Place;
use App\Models\PlacePhoto;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * cache تفاصيل المكان (`places:{slug}`، 5 دقايق، 03 القسم 5.5 و06 القسم 5).
 *
 * بيخزّن بيانات المكان وعلاقاته كـ arrays بس (`cache.serializable_classes = false` فأي Eloquent object بيطلع
 * `__PHP_Incomplete_Class` وبيرمي 500 عند القراءة التانية)، وبيبني منها `Place` جديد وقت الطلب. فالـ resource
 * بيحسب `is_open_now` والمواعيد واللغة و`distance_km` كل طلب من غير ما أي حاجة منها تتخزّن.
 *
 * بيتمسح من `ForgetPlaceDetailCache` (على `PlaceChanged`) بالـ slug الحالي والقديم. وأي تغيير في المرجعيات
 * (تصنيف أو خاصية أو مدينة أو منطقة) بيغيّر `catalogChanged()` token بيتخزن مع كل snapshot، فكله بيتعامل كـ miss
 * (الـ file cache مفيهوش tags ولا مسح بنمط). تغيير في صورة من غير حفظ المكان نفسه بيتأخر لحد 5 دقايق.
 *
 * ما بيتخزنش `internal_notes` ولا `search_text` ولا `location`: ما بيطلعوش في أي response.
 */
final class PlaceDetailsCache
{
    public const TTL_MINUTES = 5;

    /** مفتاح الـ token اللي بيتغيّر مع أي تعديل في المرجعيات. */
    private const CATALOG_TOKEN_KEY = 'places:catalog';

    /** الأعمدة اللي ما بتتخزنش في الـ snapshot. */
    private const EXCLUDED = ['location', 'distance_m', 'search_text', 'internal_notes'];

    /**
     * المكان المنشور أو المغلق نهائياً بالـ slug (أو slug قديم)، من الـ cache أو الداتابيز. null لو مش
     * متاح (النتيجة الفاضية ما بتتخزنش، فالإظهار بيبان فوراً).
     */
    public static function find(string $slug): ?Place
    {
        $key = Place::detailsCacheKey($slug);
        $snapshot = Cache::get($key);

        if (! is_array($snapshot) || ($snapshot['catalog'] ?? null) !== self::catalogToken()) {
            $place = self::lookup($slug);
            if ($place === null) {
                return null;
            }

            $snapshot = self::snapshot($place);
            Cache::put($key, $snapshot, now()->addMinutes(self::TTL_MINUTES));
        }

        return self::hydrate($snapshot);
    }

    /**
     * أي تعديل في تصنيف أو خاصية أو مدينة أو منطقة بيعمل snapshots التفاصيل كلها stale (بيتنادى من
     * `IsCatalogItem::forgetCatalogCache`).
     */
    public static function catalogChanged(): void
    {
        Cache::forever(self::CATALOG_TOKEN_KEY, (string) Str::uuid());
    }

    private static function catalogToken(): string
    {
        return (string) Cache::get(self::CATALOG_TOKEN_KEY, '0');
    }

    private static function lookup(string $slug): ?Place
    {
        $query = fn () => Place::query()
            ->viewable()
            ->with(['primaryCategory', 'area', 'coverPhoto', 'categories', 'placeAttributes', 'photos'])
            ->select('places.*');

        return $query()->where('places.slug', $slug)->first()
            ?? $query()->whereIn(
                'places.id',
                DB::table('place_slug_redirects')->where('old_slug', $slug)->select('place_id'),
            )->first();
    }

    /**
     * @return array<string, mixed>
     */
    private static function snapshot(Place $place): array
    {
        $attributes = fn ($model) => $model === null ? null : $model->getAttributes();
        $list = fn ($models) => $models->map(fn ($model) => $model->getAttributes())->values()->all();

        return [
            'catalog' => self::catalogToken(),
            'place' => Arr::except($place->getAttributes(), self::EXCLUDED),
            'primaryCategory' => $attributes($place->primaryCategory),
            'area' => $attributes($place->area),
            'coverPhoto' => $attributes($place->coverPhoto),
            'categories' => $list($place->categories),
            'placeAttributes' => $list($place->placeAttributes),
            'photos' => $list($place->photos),
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private static function hydrate(array $snapshot): Place
    {
        $place = (new Place)->newFromBuilder($snapshot['place']);

        $one = fn (string $class, ?array $attributes) => $attributes === null ? null : (new $class)->newFromBuilder($attributes);

        $place->setRelation('primaryCategory', $one(Category::class, $snapshot['primaryCategory']));
        $place->setRelation('area', $one(Area::class, $snapshot['area']));
        $place->setRelation('coverPhoto', $one(PlacePhoto::class, $snapshot['coverPhoto']));
        $place->setRelation('categories', Category::hydrate($snapshot['categories']));
        $place->setRelation('placeAttributes', Attribute::hydrate($snapshot['placeAttributes']));
        $place->setRelation('photos', PlacePhoto::hydrate($snapshot['photos']));

        return $place;
    }
}
