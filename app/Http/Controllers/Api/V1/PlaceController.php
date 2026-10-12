<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\IndexPlacesRequest;
use App\Http\Requests\NearbyPlacesRequest;
use App\Http\Resources\PlaceCardResource;
use App\Models\Place;
use App\Support\OpenNow;
use App\Support\PlaceLocation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class PlaceController extends Controller
{
    /**
     * GET /places — القائمة بكروت المكان مع pagination (06 القسم 1: `data` و`meta` من 4 حقول).
     * بترجّع المنشور بس. الفلاتر الكاملة مع F5 (أسبوع 4)، والـ cache مع /home (أسبوع 8).
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

        if ($area = $request->validated('area')) {
            $query->whereHas('area', fn (Builder $q) => $q->where('slug', $area));
        }

        if ($categories = $request->validated('categories')) {
            // تصنيف المكان (الرئيسي أو الإضافي) جوه المختار. الرئيسي دايماً موجود في place_category.
            $query->whereIn('places.id', DB::table('place_category')
                ->join('categories', 'categories.id', '=', 'place_category.category_id')
                ->whereIn('categories.slug', $categories)
                ->select('place_category.place_id'));
        }

        if ($request->openNow()) {
            $query->openNow($now);
        }

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
