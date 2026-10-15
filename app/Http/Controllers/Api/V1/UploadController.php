<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\UploadPhotoRequest;
use App\Jobs\ProcessReviewPhoto;
use App\Models\ReviewPhoto;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

/**
 * رفع مسبق للصور (F8، 06 القسم 3): الصورة بتتحفظ وبترجّع `id` فوراً، والمعالجة (3 نسخ WebP ومسح EXIF) في الخلفية.
 * التطبيق بيبعت الـ `id` في `photo_ids` مع التقييم، فالمستخدم يقدر يخرج من الشاشة والصور لسه بتتعالج.
 *
 * @group Uploads
 */
class UploadController extends Controller
{
    /** صور مرفوعة ولسه ما اتربطتش بتقييم في آخر 24 ساعة: أكتر من كده تحميل مش مفهوم. */
    private const MAX_UNATTACHED = 15;

    /**
     * Upload a photo
     *
     * `multipart/form-data`: `photo` (JPG أو PNG أو WebP، لحد 8MB) و`context` = `review`. بيرجّع `id` والحالة
     * `processing`. محتاج إيميل متأكَّد (`403 EMAIL_NOT_VERIFIED`). الصورة اللي ما اتربطتش بتقييم بعد 24 ساعة
     * بتتمسح. أكتر من 15 صورة معلّقة: `429 DAILY_LIMIT_REACHED`.
     *
     * @authenticated
     *
     * @response 201 {"data": {"id": 12, "status": "processing"}}
     */
    public function photo(UploadPhotoRequest $request): JsonResponse
    {
        $user = $request->user();

        $unattached = ReviewPhoto::query()
            ->where('user_id', $user->id)
            ->whereNull('review_id')
            ->where('created_at', '>', now()->subDay())
            ->count();

        if ($unattached >= self::MAX_UNATTACHED) {
            throw new ApiException(429, 'DAILY_LIMIT_REACHED');
        }

        $file = $request->file('photo');

        $path = $file->storeAs(
            "review-originals/{$user->id}",
            Str::uuid().'.'.($file->guessExtension() ?: 'jpg'),
            config('wain.photos.original_disk'),
        );

        $photo = ReviewPhoto::query()->create([
            'user_id' => $user->id,
            'path' => $path,
            'status' => ReviewPhoto::STATUS_PROCESSING,
            'created_at' => now(),
        ]);

        ProcessReviewPhoto::dispatch($photo->id);

        return response()->json(['data' => ['id' => $photo->id, 'status' => $photo->status]], 201);
    }
}
