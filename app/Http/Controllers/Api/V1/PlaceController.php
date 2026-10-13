<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\CountPlacesRequest;
use App\Http\Requests\IndexPlacesRequest;
use App\Http\Requests\NearbyPlacesRequest;
use App\Http\Requests\ShowPlaceRequest;
use App\Http\Resources\PlaceCardResource;
use App\Http\Resources\PlaceDetailResource;
use App\Models\Place;
use App\Support\OpenNow;
use App\Support\PlaceLocation;
use Illuminate\Database\Eloquent\Builder;
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
     * PlaceFilterRequest، والـ cache مع /home (أسبوع 8).
     */
    public function index(IndexPlacesRequest $request): JsonResponse
    {
        // لحظة واحدة للطلب كله: الفلتر وعلامة is_open_now في الكروت بيحسبوا من نفس الدقيقة
        $now = OpenNow::at();
        $request->attributes->set(PlaceCardResource::OPEN_NOW_ATTRIBUTE, $now);

        $query = Place::query()
            ->published()
            ->with(['primaryCategory', 'area', 'coverPhoto'])
            ->select('places.*');

        $request->applyFilters($query, $now);

        if ($request->hasLocation()) {
            $lat = (float) $request->validated('lat');
            $lng = (float) $request->validated('lng');
            $query->selectRaw(PlaceLocation::distanceMetersSql($lat, $lng).' as distance_m');
        }

        $this->applySort($query, $request);

        $page = $query->paginate($request->perPage());

        return response()->json([
            'data' => PlaceCardResource::collection($page->getCollection())->resolve($request),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
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
     * بيفلتر أولاً بمستطيل حوالين النقطة (MBRContains، بيستخدم الـ SPATIAL INDEX)، وبعدين بالمسافة الدقيقة.
     * القائمة صغيرة (limit أقصى 30) فمفيش meta.
     */
    public function nearby(NearbyPlacesRequest $request): JsonResponse
    {
        $request->attributes->set(PlaceCardResource::OPEN_NOW_ATTRIBUTE, OpenNow::at());

        $lat = $request->lat();
        $lng = $request->lng();
        $distance = PlaceLocation::distanceMetersSql($lat, $lng);

        $places = Place::query()
            ->published()
            ->with(['primaryCategory', 'area', 'coverPhoto'])
            ->select('places.*')
            ->selectRaw($distance.' as distance_m')
            ->whereRaw(PlaceLocation::boundingBoxSql($lat, $lng, $request->radiusKm()))
            ->whereRaw($distance.' <= ?', [$request->radiusKm() * 1000])
            ->orderBy('distance_m')
            ->orderBy('places.id')
            ->limit($request->limit())
            ->get();

        return response()->json([
            'data' => PlaceCardResource::collection($places)->resolve($request),
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
     *
     * @urlParam slug string required slug المكان. Example: el-mahatta-coffee
     *
     * @responseFile 200 resources/api-examples/place-detail.json
     *
     * @response 404 scenario="مخفي أو مسودة أو محذوف أو مش موجود" {"message": "المكان مش متاح دلوقتي.", "code": "PLACE_NOT_AVAILABLE"}
     */
    public function show(ShowPlaceRequest $request, string $slug): JsonResponse
    {
        // الـ cache (5 دقايق بمفتاح فيه رقم نسخة، 03 القسم 5.5) بيتضاف مع PlaceObserver في أسبوع 4:
        // من غير الـ Observer مفيش حاجة تمسحه.
        $request->attributes->set(PlaceCardResource::OPEN_NOW_ATTRIBUTE, OpenNow::at());

        $query = Place::query()
            ->viewable()
            ->with(['primaryCategory', 'area', 'coverPhoto', 'categories', 'placeAttributes', 'photos'])
            ->select('places.*');

        if ($request->hasLocation()) {
            $query->selectRaw(PlaceLocation::distanceMetersSql($request->lat(), $request->lng()).' as distance_m');
        }

        $place = (clone $query)->where('places.slug', $slug)->first()
            ?? $query->whereIn('places.id', DB::table('place_slug_redirects')->where('old_slug', $slug)->select('place_id'))->first();

        if ($place === null) {
            throw new ApiException(404, 'PLACE_NOT_AVAILABLE');
        }

        return response()->json([
            'data' => (new PlaceDetailResource($place))->resolve($request),
        ]);
    }

    /**
     * @param  Builder<Place>  $query
     */
    private function applySort(Builder $query, IndexPlacesRequest $request): void
    {
        match ($request->sort()) {
            'nearest' => $query->orderBy('distance_m'),
            'rating' => $query->orderByDesc('rating_avg')->orderByDesc('rating_count'),
            'newest' => $query->orderByDesc('published_at'),
            default => $this->applyRecommended($query),
        };

        // ترتيب ثابت يمنع تكرار أو ضياع عنصر بين صفحتين
        $query->orderByDesc('places.id');
    }

    /**
     * الترتيب المقترح (03 القسم 7.3): الباقة (premium ثم featured ثم free)، جوه المدفوعة ترتيب
     * بيتبدّل كل يوم بتوقيت القاهرة، وجوه المجاني اللي له 3 تقييمات أو أكتر الأول بالتقييم، والتعادل
     * بالأحدث نشراً.
     *
     * @param  Builder<Place>  $query
     */
    private function applyRecommended(Builder $query): void
    {
        $today = now('Africa/Cairo')->toDateString();

        $query
            ->orderBy('tier_rank')
            ->orderByRaw("CASE WHEN tier_rank < 3 THEN MD5(CONCAT(places.id, '|', ?)) END", [$today])
            ->orderByRaw('(rating_count >= 3) DESC')
            ->orderByDesc('rating_avg')
            ->orderByDesc('published_at');
    }
}
