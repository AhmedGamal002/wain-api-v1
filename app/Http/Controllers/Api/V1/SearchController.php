<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\SearchPlacesRequest;
use App\Http\Resources\PlaceCardResource;
use App\Models\Place;
use App\Support\OpenNow;
use App\Support\PlaceListOrder;
use App\Support\PlaceLocation;
use App\Support\Search\PlaceSearch;
use App\Support\Search\SearchLogger;
use App\Support\Search\SearchPlan;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;

/**
 * @group Places
 */
class SearchController extends Controller
{
    /**
     * أعمدة كارت المكان بس (PlaceCardResource و is_open_now): بنستثني `search_text` والوصف والملاحظات، لإن الـ
     * sort على relevance بيشيل صفوف كبيرة في الذاكرة لو اتحمّلت (القياس على 5000 مكان في CLAUDE.md).
     */
    private const CARD_COLUMNS = [
        'places.id', 'places.slug', 'places.name_ar', 'places.name_en',
        'places.primary_category_id', 'places.area_id', 'places.cover_photo_id',
        'places.price_min', 'places.price_max', 'places.price_range',
        'places.rating_avg', 'places.rating_count', 'places.listing_tier',
        'places.latitude', 'places.longitude', 'places.opening_hours', 'places.ramadan_hours',
    ];

    public function __construct(private readonly PlaceSearch $search) {}

    /**
     * Search places
     *
     * بحث في الأماكن المنشورة بنفس شكل كارت `GET /places` ونفس فلاتر F5 (المنطقة والتصنيفات والخصائص
     * والسعر والتقييم و`open_now`) والترتيب و`lat`/`lng` والـ pagination، وكلها بتتطبّق في نفس الاستعلام.
     *
     * - **التطبيع:** `أ إ آ` = `ا`، و`ة` = `ه`، و`ى` = `ي`، من غير تشكيل ولا «ال» التعريف.
     * - **المرادفات:** كوفي = كافيه = cafe = coffee، وروف = roof = rooftop، وشيشة = shisha (قاموس في الـ repo).
     * - **الفرانكو:** بيتطابق بالـ `search_aliases` اللي الأدمن بيضيفها للمكان وبقاموس المرادفات.
     * - **الكلمات:** كل كلمة لازم تكون موجودة (AND). الاسم أول، بعده الـ alias، بعده الباقي.
     * - **التصحيح الإملائي:** لو النتايج أقل من 3 بتتصحّح الكلمات الغلط، و`meta.corrected_query` بيرجع بالكلمة
     *   اللي اتبحث بيها فعلاً (`null` لو ما اتصحّحش حاجة). الموبايل بيعرض «نتايج لـ …».
     * - **الترتيب:** `sort=recommended` (الافتراضي) بيرتّب بالتطابق الأول ثم بالترتيب المقترح، والباقي (`nearest`
     *   و`rating` و`newest`) بترتيبها زي القائمة.
     * - علامات الترقيم بس (أو كلمة من غير حروف) بترجّع قايمة فاضية مش خطأ.
     * - **`log=1`:** بيسجّل العملية في سجل البحث (الكلمة مطبَّعة والمنطقة وعدد النتايج والمستخدم والجهاز) لتقارير
     *   «أكتر بحث» و«بحث من غير نتائج». الموبايل بيبعتها لما المستخدم يدوس Enter أو يفتح نتيجة أو تثبت شاشة «مفيش
     *   نتائج» لثانية بس. السجل بيتمسح بعد 90 يوم.
     *
     * @response 200 scenario="نتايج" {"data": [], "meta": {"current_page": 1, "last_page": 1, "per_page": 20, "total": 0, "corrected_query": null}}
     */
    public function index(SearchPlacesRequest $request): JsonResponse
    {
        // لحظة واحدة للطلب كله: فلتر «مفتوح دلوقتي» وعلامة is_open_now في الكروت من نفس الدقيقة
        $now = OpenNow::at();
        $request->attributes->set(PlaceCardResource::OPEN_NOW_ATTRIBUTE, $now);

        $plan = $this->search->plan($request->searchQuery());

        if ($plan->isEmpty()) {
            return $this->respond($request, null, null);
        }

        $page = $this->page($request, $plan, $now);
        $correctedQuery = null;

        if ($page->total() < PlaceSearch::MIN_RESULTS_WITHOUT_CORRECTION) {
            $fixed = $this->search->correct($plan);

            if ($fixed !== null) {
                $retry = $this->page($request, $fixed, $now);

                // التصحيح بيتقبل لو جاب نتايج أكتر من الكلمة اللي المستخدم كتبها
                if ($retry->total() > $page->total()) {
                    $page = $retry;
                    $correctedQuery = $fixed->text();
                }
            }
        }

        if ($request->boolean('log')) {
            // اللي المستخدم شافه فعلاً: عدد النتايج بعد التصحيح لو اتقبل، والكلمة زي ما كتبها (مطبَّعة)
            SearchLogger::record(
                $plan->query,
                $page->total(),
                $request->validated('area'),
                $request->user('sanctum')?->getAuthIdentifier(),
                $request->header('X-Device-Id'),
            );
        }

        return $this->respond($request, $page, $correctedQuery);
    }

    /**
     * @return LengthAwarePaginator<int, Place>
     */
    private function page(SearchPlacesRequest $request, SearchPlan $plan, OpenNow $now): LengthAwarePaginator
    {
        $query = Place::query()
            ->published()
            ->with(['primaryCategory', 'area', 'coverPhoto'])
            ->select(self::CARD_COLUMNS);

        $request->applyFilters($query, $now);
        $this->search->constrain($query, $plan);

        if ($request->hasLocation()) {
            $query->selectRaw(PlaceLocation::distanceMetersSql(
                (float) $request->validated('lat'),
                (float) $request->validated('lng'),
            ).' as distance_m');
        }

        // التطابق أول ما الترتيب «مقترح»؛ لو المستخدم اختار الأقرب أو التقييم أو الأحدث الترتيب بتاعه هو
        if ($request->sort() === 'recommended') {
            $query->orderByDesc('relevance');
        }

        PlaceListOrder::apply($query, $request->sort());

        return $query->paginate($request->perPage());
    }

    /**
     * @param  LengthAwarePaginator<int, Place>|null  $page  null = مفيش نتايج من غير ما نسأل الداتابيز
     */
    private function respond(SearchPlacesRequest $request, ?LengthAwarePaginator $page, ?string $correctedQuery): JsonResponse
    {
        return response()->json([
            'data' => $page === null ? [] : PlaceCardResource::collection($page->getCollection())->resolve($request),
            'meta' => [
                'current_page' => $page?->currentPage() ?? 1,
                'last_page' => $page?->lastPage() ?? 1,
                'per_page' => $page?->perPage() ?? $request->perPage(),
                'total' => $page?->total() ?? 0,
                'corrected_query' => $correctedQuery,
            ],
        ]);
    }
}
