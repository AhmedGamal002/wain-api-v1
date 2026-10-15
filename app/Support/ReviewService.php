<?php

namespace App\Support;

use App\Exceptions\ApiException;
use App\Jobs\RecalculatePlaceRating;
use App\Models\Attribute;
use App\Models\Place;
use App\Models\Review;
use App\Models\ReviewPhoto;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * التقييم من ناحية صاحبه (F8، 02): إرسال وتعديل وحذف. القواعد في مكان واحد، والـ controller بيتحقق من الشكل
 * (`StoreReviewRequest`) وبينادي هنا. قرار الفريق (موافقة ورفض) في `ReviewModeration`.
 *
 * - التقييم الجديد بيدخل `pending` (بيظهر لصاحبه بس) لحد موافقة الفريق.
 * - تعديل منشور: النسخة الجديدة في `pending_payload` والمنشور بيفضل ظاهر بقيمه (ومحسوب في المتوسط) لحد الموافقة.
 * - تعديل `pending` أو `rejected`: على نفس الصف، وبيرجع `pending` تاني.
 * - حذف صاحبه بيمسحه نهائياً (بصوره) ويعيد حساب المتوسط.
 *
 * `$data` متطبّع من الـ request: `rating` و`body` (أو null) و`visited_at` (أول يوم في الشهر أو null) و`attributes`
 * (slugs) و`photo_ids`.
 *
 * @phpstan-type ReviewData array{rating: int, body: string|null, visited_at: CarbonInterface|null, attributes: list<string>, photo_ids: list<int>}
 */
final class ReviewService
{
    public const MAX_PHOTOS = 5;

    public const MAX_ATTRIBUTES = 5;

    public function __construct(private readonly PlacePhotoProcessor $processor) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function submit(User $user, Place $place, array $data): Review
    {
        if (Review::query()->where('place_id', $place->id)->where('user_id', $user->id)->exists()) {
            throw new ApiException(409, 'ALREADY_REVIEWED');
        }

        try {
            $review = DB::transaction(function () use ($user, $place, $data): Review {
                $review = Review::query()->create([
                    'place_id' => $place->id,
                    'user_id' => $user->id,
                    'rating' => $data['rating'],
                    'body' => $data['body'],
                    'visited_at' => $data['visited_at'],
                    'status' => Review::STATUS_PENDING,
                    'edit_status' => Review::EDIT_NONE,
                    'submitted_at' => now(),
                ]);

                $this->syncAttributes($review, $data['attributes']);
                $this->setPhotos($review, $data['photo_ids']);

                return $review;
            });
        } catch (UniqueConstraintViolationException) {
            // طلبين مع بعض: التاني بيخسر على الـ unique (place_id, user_id)
            throw new ApiException(409, 'ALREADY_REVIEWED');
        }

        return $this->fresh($review);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Review $review, array $data): Review
    {
        // المخفي بسبب بلاغات بيتقرر فيه من الفريق، مش بيتعدّل
        if ($review->status === Review::STATUS_HIDDEN) {
            throw new ApiException(403, 'FORBIDDEN');
        }

        if ($review->status === Review::STATUS_PUBLISHED) {
            $review->forceFill([
                'edit_status' => Review::EDIT_PENDING,
                'pending_payload' => [
                    'rating' => $data['rating'],
                    'body' => $data['body'],
                    'visited_at' => $data['visited_at']?->toDateString(),
                    'attributes' => $data['attributes'],
                    'photo_ids' => $data['photo_ids'],
                ],
                'edit_reason' => null,
                'submitted_at' => now(),
            ])->save();

            return $this->fresh($review);
        }

        DB::transaction(function () use ($review, $data): void {
            $review->forceFill([
                'rating' => $data['rating'],
                'body' => $data['body'],
                'visited_at' => $data['visited_at'],
                'status' => Review::STATUS_PENDING,
                'status_reason' => null,
                'submitted_at' => now(),
            ])->save();

            $this->syncAttributes($review, $data['attributes']);
            $this->setPhotos($review, $data['photo_ids']);
        });

        return $this->fresh($review);
    }

    public function delete(Review $review): void
    {
        $placeId = $review->place_id;
        $counted = $review->status === Review::STATUS_PUBLISHED;

        // صور التعديل اللي لسه ما اتربطتش
        $pending = is_array($review->pending_payload) ? ($review->pending_payload['photo_ids'] ?? []) : [];

        DB::transaction(function () use ($review, $pending): void {
            $review->photos()->get()->each(fn (ReviewPhoto $photo) => $this->discardPhoto($photo));

            if ($pending !== []) {
                ReviewPhoto::query()
                    ->where('user_id', $review->user_id)
                    ->whereNull('review_id')
                    ->whereIn('id', $pending)
                    ->get()
                    ->each(fn (ReviewPhoto $photo) => $this->discardPhoto($photo));
            }

            $review->delete();
        });

        if ($counted) {
            RecalculatePlaceRating::dispatch($placeId);
        }
    }

    /**
     * الصور اللي التقييم هيبقى فيها بالترتيب: اللي في القايمة بتتربط (لو بتاعة صاحب التقييم ومتاحة)، واللي كانت
     * مربوطة وطلعت من القايمة بتتمسح.
     *
     * @param  list<int>  $photoIds
     */
    public function setPhotos(Review $review, array $photoIds): void
    {
        $photoIds = array_values(array_unique(array_map('intval', $photoIds)));

        $available = ReviewPhoto::query()
            ->where('user_id', $review->user_id)
            ->whereIn('id', $photoIds)
            ->where(fn ($query) => $query->whereNull('review_id')->orWhere('review_id', $review->id))
            ->get()
            ->keyBy('id');

        $kept = [];

        foreach ($photoIds as $position => $id) {
            $photo = $available->get($id);

            if ($photo === null) {
                continue;
            }

            $photo->forceFill(['review_id' => $review->id, 'sort_order' => min($position, 255)])->save();
            $kept[] = $photo->id;
        }

        ReviewPhoto::query()
            ->where('review_id', $review->id)
            ->whereNotIn('id', $kept)
            ->get()
            ->each(fn (ReviewPhoto $photo) => $this->discardPhoto($photo));
    }

    /**
     * @param  list<string>  $slugs
     */
    public function syncAttributes(Review $review, array $slugs): void
    {
        $ids = Attribute::query()
            ->where('is_active', true)
            ->whereIn('slug', array_values(array_unique($slugs)))
            ->limit(self::MAX_ATTRIBUTES)
            ->pluck('id')
            ->all();

        $review->reviewAttributes()->sync($ids);
    }

    /**
     * يمسح الصورة: نسخها (WebP) والأصل والصف.
     */
    public function discardPhoto(ReviewPhoto $photo): void
    {
        $this->processor->deleteVariants($photo);

        if (is_string($photo->path) && $photo->path !== '') {
            Storage::disk(config('wain.photos.original_disk'))->delete($photo->path);
        }

        $photo->delete();
    }

    private function fresh(Review $review): Review
    {
        return $review->refresh()->load(['user', 'reviewAttributes', 'photos']);
    }
}
