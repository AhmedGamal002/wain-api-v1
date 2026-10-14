<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\CountPlacesRequest;
use App\Http\Requests\IndexPlacesRequest;
use App\Http\Requests\MapPlacesRequest;
use App\Http\Requests\NearbyPlacesRequest;
use App\Http\Requests\ShowPlaceRequest;
use App\Http\Resources\PlaceCardResource;
use App\Http\Resources\PlaceDetailResource;
use App\Http\Resources\PlacePinResource;
use App\Models\Place;
use App\Support\NearbyPlaces;
use App\Support\OpenNow;
use App\Support\PlaceDetailsCache;
use App\Support\PlaceListCache;
use App\Support\PlaceListOrder;
use App\Support\PlaceLocation;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * @group Places
 */
class PlaceController extends Controller
{
    /**
     * GET /places — القائمة بكروت المكان مع pagination (06 القسم 1: `data` و`meta` من 4 حقول).
     * بترجّع المنشور بس، بالفلاتر (المنطقة والتصنيفات والخصائص والسعر والتقييم و open_now) من
     * PlaceFilterRequest.
     *
     * ترتيب الـ ids وعدد النتايج بس هما اللي في cache لمدة 60 ثانية (`PlaceListCache`، بيتمسح مع أي تغيير في
     * الأماكن)؛ الكارت بيتبني من الداتابيز كل طلب بـ `is_open_now` والمسافة واللغة.
     */
    public function index(IndexPlacesRequest $request): JsonResponse
    {
        // لحظة واحدة للطلب كله: الفلتر وعلامة is_open_now في الكروت بيحسبوا من نفس الدقيقة
        $now = OpenNow::at();
        $request->attributes->set(PlaceCardResource::OPEN_NOW_ATTRIBUTE, $now);

        $listing = PlaceListCache::remember($request, fn () => $this->listing($request, $now));

        return response()->json([
            'data' => PlaceCardResource::collection($this->cards($request, $listing['ids']))->resolve($request),
            'meta' => $listing['meta'],
        ]);
    }

    /**
     * الصفحة المطلوبة: ترتيب الـ ids وعدّاد الـ pagination، من غير تحميل الكروت.
     *
     * @return array{ids: list<int>, meta: array<string, int>}
     */
    private function listing(IndexPlacesRequest $request, OpenNow $now): array
    {
        $query = Place::query()->published()->select('places.id');

        $request->applyFilters($query, $now);

        // المسافة محتاجة هنا بس لما الترتيب نفسه بالأقرب؛ عرضها في الكارت من `cards`
        if ($request->sort() === 'nearest' && $request->hasLocation()) {
            $query->selectRaw(PlaceLocation::distanceMetersSql(
                (float) $request->validated('lat'),
                (float) $request->validated('lng'),
            ).' as distance_m');
        }

        PlaceListOrder::apply($query, $request->sort());

        $page = $query->paginate($request->perPage());

        return [
            'ids' => $page->getCollection()->pluck('id')->map(fn ($id) => (int) $id)->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ];
    }

    /**
     * كروت الـ ids دي بنفس ترتيبها. المكان اللي اتخفى أو اتحذف من وقت ما الـ ids اتخزنت بيطلع من القايمة
     * (الصفحة ممكن تبقى أقصر بمكان لحد ما الـ cache ينتهي).
     *
     * @param  list<int>  $ids
     * @return Collection<int, Place>
     */
    private function cards(IndexPlacesRequest $request, array $ids): Collection
    {
        if ($ids === []) {
            return new Collection;
        }

        $query = Place::query()
            ->published()
            ->with(['primaryCategory', 'area', 'coverPhoto'])
            ->select('places.*')
            ->whereIn('places.id', $ids);

        if ($request->hasLocation()) {
            $query->selectRaw(PlaceLocation::distanceMetersSql(
                (float) $request->validated('lat'),
                (float) $request->validated('lng'),
            ).' as distance_m');
        }

        $byId = $query->get()->keyBy('id');

        return new Collection(array_values(array_filter(array_map(fn (int $id) => $byId->get($id), $ids))));
    }

    /**
     * Count places
     *
     * عدد الأماكن بنفس فلاتر `GET /places` (للزرار «ورّيني 23 مكان» في شاشة الفلاتر). بيرجّع المنشور بس،
     * ومن غير pagination. بيقبل نفس الـ query بتاعة القائمة وبيتجاهل الترتيب والموقع والصفحة.
     *
     * @response 200 {"count": 23}
     */
    public function count(CountPlacesRequest $request): JsonResponse
    {
        $query = Place::query()->published();

        $request->applyFilters($query, OpenNow::at());

        return response()->json(['count' => $query->count()]);
    }

    /**
     * GET /places/nearby — «قريب منك»: المنشور جوه نصف القطر مرتب بالأقرب (03 القسم 7.5).
     *
     * القائمة صغيرة (limit أقصى 30) فمفيش meta. الاستعلام نفسه في `NearbyPlaces` (مشترك مع `GET /home`).
     */
    public function nearby(NearbyPlacesRequest $request): JsonResponse
    {
        $request->attributes->set(PlaceCardResource::OPEN_NOW_ATTRIBUTE, OpenNow::at());

        $places = NearbyPlaces::find($request->lat(), $request->lng(), $request->radiusKm(), $request->limit());

        return response()->json([
            'data' => PlaceCardResource::collection($places)->resolve($request),
        ]);
    }

    /**
     * Map pins
     *
     * pins الخريطة (F6): المنشور جوه مستطيل `bbox` بنفس فلاتر `GET /places`، لحد 200 مرتبين بالترتيب المقترح
     * (premium ثم featured ثم الباقي)، فلو الجزء الظاهر فيه أكتر من 200 بيطلع المدفوع الأول.
     *
     * - كل pin: `id` و`slug` و`latitude` و`longitude` و`category_slug` (اللون من `GET /categories`) و`listing_tier`
     *   (premium بيتعرض أكبر). الكارت بيتحمّل لما المستخدم يدوس على pin من `GET /places/{slug}`.
     * - من غير `meta` (زي `nearby`): لو الرد 200 pin بالظبط يبقى غالباً فيه أكتر في المستطيل ده، والموبايل يقترح التقريب.
     * - ما فيش ترتيب ولا pagination ولا موقع: الخريطة بتتحرك والمستخدم بيدوس «ابحث في المنطقة دي».
     *
     * @response 200 {"data": [{"id": 152, "slug": "cilantro-new-cairo", "latitude": 30.0283, "longitude": 31.4913, "category_slug": "cafes", "listing_tier": "premium"}]}
     * @response 422 scenario="bbox ناقص أو غلط" {"message": "حدود الخريطة لازم تبقى ...", "code": "VALIDATION_ERROR", "errors": {"bbox": ["..."]}}
     */
    public function map(MapPlacesRequest $request): JsonResponse
    {
        [$minLng, $minLat, $maxLng, $maxLat] = $request->bbox();

        $query = Place::query()
            ->published()
            ->with('primaryCategory:id,slug')
            ->select(['places.id', 'places.slug', 'places.latitude', 'places.longitude', 'places.primary_category_id', 'places.listing_tier'])
            // المستطيل الأول: بيستخدم الـ SPATIAL INDEX، والفلاتر بعده بتشتغل على النتيجة الصغيرة
            ->whereRaw(PlaceLocation::rectangleSql($minLat, $minLng, $maxLat, $maxLng));

        $request->applyFilters($query, OpenNow::at());

        PlaceListOrder::apply($query, 'recommended');

        $places = $query->limit(MapPlacesRequest::LIMIT)->get();

        return response()->json([
            'data' => PlacePinResource::collection($places)->resolve($request),
        ]);
    }

    /**
     * Get place details
     *
     * تفاصيل المكان. صفحة المكان (F7): كل حقول الكارت + الوصف والعنوان والتصنيفات والخصائص (متجمّعة) والتواصل والمواعيد
     * والصور والمنيو.
     *
     * - بيرجّع المنشور (`status = active`) والمغلق نهائياً (`status = closed`) بس. غير كده (مخفي أو مسودة أو
     *   قيد المراجعة أو مرفوض أو محذوف أو مش موجود) 404 `PLACE_NOT_AVAILABLE` واحدة من غير ما تكشف السبب.
     * - المغلق نهائياً: `is_open_now` و`hours.status_text` بـ `null` و`hours.week` فاضية، والموبايل بيعرض
     *   بانر «المكان ده قفل نهائياً» وبيخفي أزرار التواصل.
     * - الـ slug القديم بعد ما يتغيّر بيرجّع المكان بالـ slug الجديد (الروابط المتشاركة تفضل شغالة).
     * - الأجزاء الفاضية: `description` بـ `null`، و`attributes` و`photos` و`menu_photos` قايمة فاضية، وحقول
     *   `contact` بـ `null`.
     * - `distance_km` بـ `null` من غير `lat` و`lng`. `is_saved` دايماً `false` لحد F9.
     * - بيانات المكان في cache لمدة 5 دقايق وبتتمسح فوراً مع أي تعديل أو إخفاء. `is_open_now` والمواعيد واللغة
     *   و`distance_km` بتتحسب مع كل طلب.
     *
     * @urlParam slug string required slug المكان. Example: el-mahatta-coffee
     *
     * @responseFile 200 resources/api-examples/place-detail.json
     *
     * @response 404 scenario="مخفي أو مسودة أو محذوف أو مش موجود" {"message": "المكان مش متاح دلوقتي.", "code": "PLACE_NOT_AVAILABLE"}
     */
    public function show(ShowPlaceRequest $request, string $slug): JsonResponse
    {
        $request->attributes->set(PlaceCardResource::OPEN_NOW_ATTRIBUTE, OpenNow::at());

        // بيانات المكان من cache التفاصيل (5 دقايق، بيتمسح مع أي تغيير في المكان). اللي بيختلف حسب الطلب
        // (اللغة و`is_open_now` والمواعيد و`distance_km`) بيتحسب بعد الـ cache (03 القسم 5.5).
        $place = PlaceDetailsCache::find($slug);

        if ($place === null) {
            throw new ApiException(404, 'PLACE_NOT_AVAILABLE');
        }

        if ($request->hasLocation()) {
            $place->setAttribute('distance_m', DB::table('places')
                ->where('id', $place->id)
                ->selectRaw(PlaceLocation::distanceMetersSql($request->lat(), $request->lng()).' as distance_m')
                ->value('distance_m'));
        }

        return response()->json([
            'data' => (new PlaceDetailResource($place))->resolve($request),
        ]);
    }
}
