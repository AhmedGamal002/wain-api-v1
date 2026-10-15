<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreReviewRequest;
use App\Http\Resources\ReviewResource;
use App\Models\Place;
use App\Models\Review;
use App\Support\PlaceDetailsCache;
use App\Support\PlaceRating;
use App\Support\ReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * تقييمات المكان (F8، 06 القسم 3).
 *
 * @group Reviews
 */
class PlaceReviewController extends Controller
{
    private const DEFAULT_PER_PAGE = 10;

    private const MAX_PER_PAGE = 20;

    public function __construct(private readonly ReviewService $reviews) {}

    /**
     * List a place's reviews
     *
     * التقييمات المنشورة بس، الأحدث أول (مع pagination: `data` و`meta` من 4 حقول). ومعاها `summary`
     * (المتوسط والعدد وتوزيع النجوم، من المنشور بس) و`mine`: تقييم المستخدم الحالي للمكان بأي حالة (قيد المراجعة
     * أو مرفوض مع السبب...) أو `null`، وبيظهر له هو بس. المكان المخفي أو المحذوف أو الغلط: `404 PLACE_NOT_AVAILABLE`.
     *
     * @queryParam page integer رقم الصفحة. Example: 1
     * @queryParam per_page integer لحد 20 (الافتراضي 10). Example: 10
     *
     * @response 200 {"data": [], "meta": {"current_page": 1, "last_page": 1, "per_page": 10, "total": 0}, "summary": {"avg": 0, "count": 0, "distribution": {"5": 0, "4": 0, "3": 0, "2": 0, "1": 0}}, "mine": null}
     */
    public function index(Request $request, string $slug): JsonResponse
    {
        $place = $this->viewablePlace($slug);

        $perPage = min(max((int) $request->query('per_page', self::DEFAULT_PER_PAGE), 1), self::MAX_PER_PAGE);

        $page = Review::query()
            ->published()
            ->where('place_id', $place->id)
            ->with(['user', 'reviewAttributes', 'photos'])
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        $viewer = $request->user('sanctum');
        $mine = $viewer === null ? null : Review::query()
            ->where('place_id', $place->id)
            ->where('user_id', $viewer->getAuthIdentifier())
            ->with(['user', 'reviewAttributes', 'photos'])
            ->first();

        return response()->json([
            'data' => ReviewResource::collection($page->getCollection())->resolve($request),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
            'summary' => [
                'avg' => round((float) $place->rating_avg, 1),
                'count' => (int) $place->rating_count,
                'distribution' => PlaceRating::distribution($place->id),
            ],
            'mine' => $mine === null ? null : (new ReviewResource($mine))->resolve($request),
        ]);
    }

    /**
     * Write a review
     *
     * تقييم جديد للمكان. بيدخل `pending` ويظهر لصاحبه بس لحد موافقة الفريق. محتاج حساب بإيميل متأكَّد
     * (`403 EMAIL_NOT_VERIFIED`)، وتقييم واحد لكل مستخدم في المكان (`409 ALREADY_REVIEWED`، والتعديل بـ `PUT`).
     * الصور بـ `photo_ids` من `POST /uploads/photos`. الرأي 10–1000 حرف وممنوع فيه أرقام تليفونات وروابط.
     *
     * @authenticated
     *
     * @bodyParam rating integer required من 1 لـ 5. Example: 5
     * @bodyParam body string الرأي (10–1000 حرف). Example: مكان هادي والخدمة سريعة
     * @bodyParam attributes string[] خصائص لاحظتها (slugs، لحد 5). Example: ["quiet", "wifi"]
     * @bodyParam photo_ids integer[] صور مرفوعة قبل كده (لحد 5). Example: [12, 13]
     * @bodyParam visited_at string شهر الزيارة `YYYY-MM`. Example: 2026-09
     *
     * @response 201 {"data": {"id": 1, "rating": 5, "body": "مكان هادي والخدمة سريعة", "visited_at": "2026-09", "attributes": [], "photos": [], "user": {"name": "سلمى", "avatar_url": null, "is_deleted": false}, "published_at": null, "is_mine": true, "status": "pending", "status_reason": null, "edit": null}}
     */
    public function store(StoreReviewRequest $request, string $slug): JsonResponse
    {
        $place = $this->viewablePlace($slug);

        // التقييم لمكان منشور بس: المغلق نهائياً بيعرض تقييماته القديمة من غير ما يستقبل جديدة
        if ($place->status !== Place::STATUS_ACTIVE) {
            throw new ApiException(404, 'PLACE_NOT_AVAILABLE');
        }

        $review = $this->reviews->submit($request->user(), $place, $request->reviewData());

        return response()->json(['data' => (new ReviewResource($review))->resolve($request)], 201);
    }

    private function viewablePlace(string $slug): Place
    {
        $place = PlaceDetailsCache::find($slug);

        if ($place === null) {
            throw new ApiException(404, 'PLACE_NOT_AVAILABLE');
        }

        return $place;
    }
}
