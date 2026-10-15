<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateReviewRequest;
use App\Http\Resources\ReviewResource;
use App\Models\Review;
use App\Support\ReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * تعديل التقييم وحذفه (F8)، لصاحبه بس. تقييم حد تاني بيرجّع `404 NOT_FOUND` زي ما لو مش موجود.
 *
 * @group Reviews
 */
class ReviewController extends Controller
{
    public function __construct(private readonly ReviewService $reviews) {}

    /**
     * Edit your review
     *
     * بنفس حقول الإرسال (استبدال). تقييم **منشور** بيفضل ظاهر بقيمه القديمة ومحسوب في المتوسط، والجديد في
     * `edit` (`status: pending`) لحد موافقة الفريق. تقييم قيد المراجعة أو مرفوض بيتعدّل في مكانه ويرجع قيد
     * المراجعة. المخفي بسبب بلاغات: `403 FORBIDDEN`.
     *
     * @authenticated
     *
     * @response 200 {"data": {"id": 1, "rating": 4, "body": "…", "visited_at": null, "attributes": [], "photos": [], "user": {"name": "سلمى", "avatar_url": null, "is_deleted": false}, "published_at": "2026-10-16T09:00:00Z", "is_mine": true, "status": "published", "status_reason": null, "edit": {"status": "pending", "reason": null, "payload": {"rating": 4}}}}
     */
    public function update(UpdateReviewRequest $request, int $id): JsonResponse
    {
        $review = $this->ownReview($request, $id);

        $updated = $this->reviews->update($review, $request->reviewData());

        return response()->json(['data' => (new ReviewResource($updated))->resolve($request)]);
    }

    /**
     * Delete your review
     *
     * بيمسح التقييم وصوره نهائياً ويعيد حساب متوسط المكان.
     *
     * @authenticated
     *
     * @response 204
     */
    public function destroy(Request $request, int $id): Response
    {
        $this->reviews->delete($this->ownReview($request, $id));

        return response()->noContent();
    }

    private function ownReview(Request $request, int $id): Review
    {
        $review = Review::query()
            ->with(['user', 'reviewAttributes', 'photos'])
            ->whereKey($id)
            ->where('user_id', $request->user()->getAuthIdentifier())
            ->first();

        if ($review === null) {
            throw new ApiException(404, 'NOT_FOUND');
        }

        return $review;
    }
}
