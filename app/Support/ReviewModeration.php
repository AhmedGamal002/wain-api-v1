<?php

namespace App\Support;

use App\Events\ReviewPublished;
use App\Events\ReviewRejected;
use App\Jobs\RecalculatePlaceRating;
use App\Models\Review;
use App\Models\ReviewPhoto;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * قرار الفريق على التقييمات من طابور المراجعة (A6، 02): موافقة، موافقة مع حذف صورة، ورفض بسبب. زي
 * `PlaceTransitions`: القواعد في مكان واحد واللوحة بتنادي عليه بس، وأي طلب مباشر بيتحقق من الصلاحية
 * (`ReviewPolicy`: Admin وModerator).
 *
 * اللي بيستنى قرار حاجة من اتنين: تقييم جديد (`status = pending`)، أو تعديل على منشور (`edit_status = pending`
 * و`pending_payload`). الموافقة على التعديل بتطبّق القيم الجديدة، والرفض بيسيب المنشور القديم زي ما هو.
 *
 * بعد القرار: سجل التغييرات، وحدث (`ReviewPublished` أو `ReviewRejected`، F11 بيشترك فيه)، وإعادة حساب متوسط
 * المكان (`RecalculatePlaceRating`، من المنشور بس فالرفض ما بيغيّرش حاجة).
 *
 * بيرمي `ReviewModerationException` برسالة عربي جاهزة للعرض، وأي رفض صلاحية `AuthorizationException`.
 */
final class ReviewModeration
{
    /** أسباب الرفض (02 A6). `other` بيطلب ملاحظة. */
    public const REJECT_REASONS = [
        'abusive' => 'ألفاظ غير لائقة',
        'ad' => 'إعلان',
        'off_topic' => 'مش عن المكان',
        'photos' => 'صور غير مناسبة',
        'personal_data' => 'بيانات شخصية',
        'other' => 'تاني',
    ];

    /** `status_reason` و`edit_reason` عمودين VARCHAR(255). */
    private const REASON_MAX = 255;

    public function __construct(private readonly ReviewService $reviews) {}

    /**
     * موافقة. `$removePhotoIds`: صور بتتمسح قبل النشر («موافقة مع حذف صورة»)، من صور التقييم الحالية أو بتاعة
     * التعديل المنتظر.
     *
     * @param  list<int>  $removePhotoIds
     */
    public function approve(Review $review, User $actor, array $removePhotoIds = []): Review
    {
        Gate::forUser($actor)->authorize('moderate', $review);

        $isEdit = $this->requireAwaiting($review);

        DB::transaction(function () use ($review, $actor, $removePhotoIds, $isEdit): void {
            if ($isEdit) {
                $this->applyEdit($review, $removePhotoIds);
            } else {
                $this->discardPhotos($review, $removePhotoIds);
            }

            $review->forceFill([
                'status' => Review::STATUS_PUBLISHED,
                'status_reason' => null,
                'edit_status' => Review::EDIT_NONE,
                'pending_payload' => null,
                'edit_reason' => null,
                'published_at' => $review->published_at ?? now(),
                'moderated_by' => $actor->id,
                'moderated_at' => now(),
            ])->save();

            $this->log($review, $actor, $isEdit ? 'edit_approved' : 'approved', ['removed_photos' => $removePhotoIds]);
        });

        RecalculatePlaceRating::dispatch($review->place_id);
        ReviewPublished::dispatch($review->refresh(), $isEdit);

        return $review;
    }

    /**
     * رفض بسبب إلزامي (`other` بيطلب ملاحظة).
     */
    public function reject(Review $review, User $actor, string $reason, ?string $notes = null): Review
    {
        Gate::forUser($actor)->authorize('moderate', $review);

        $isEdit = $this->requireAwaiting($review);
        $text = $this->reasonText($reason, $notes);

        DB::transaction(function () use ($review, $actor, $isEdit, $text, $reason): void {
            if ($isEdit) {
                $this->dropPendingPhotos($review);

                $review->forceFill([
                    'edit_status' => Review::EDIT_REJECTED,
                    'edit_reason' => $text,
                    'pending_payload' => null,
                ]);
            } else {
                $review->forceFill([
                    'status' => Review::STATUS_REJECTED,
                    'status_reason' => $text,
                ]);
            }

            $review->forceFill(['moderated_by' => $actor->id, 'moderated_at' => now()])->save();

            $this->log($review, $actor, $isEdit ? 'edit_rejected' : 'rejected', ['reason' => $reason, 'text' => $text]);
        });

        // الرفض ما بيغيّرش المتوسط (المحسوب من المنشور بس)، فمفيش إعادة حساب
        ReviewRejected::dispatch($review->refresh(), $text, $isEdit);

        return $review;
    }

    /**
     * صور اللي بيستنى قرار بالنسبة للمشرف (للـ modal «حذف صورة»): الحالية، وبتاعة التعديل المنتظر.
     *
     * @return list<ReviewPhoto>
     */
    public static function photosUnderReview(Review $review): array
    {
        $ids = $review->photos->pluck('id')->all();

        if ($review->hasPendingEdit()) {
            $ids = array_merge($ids, array_map('intval', $review->pending_payload['photo_ids'] ?? []));
        }

        return ReviewPhoto::query()->whereIn('id', array_unique($ids))->orderBy('sort_order')->orderBy('id')->get()->all();
    }

    // ───────────────────────── القواعد ─────────────────────────

    /**
     * true لو القرار على تعديل (`edit_status = pending`)، false لتقييم جديد.
     */
    private function requireAwaiting(Review $review): bool
    {
        $review->refresh();

        if ($review->hasPendingEdit()) {
            return true;
        }

        if ($review->isNewSubmission()) {
            return false;
        }

        throw new ReviewModerationException('اتاخد قرار على التقييم ده قبل كده');
    }

    private function reasonText(string $reason, ?string $notes): string
    {
        if (! array_key_exists($reason, self::REJECT_REASONS)) {
            throw new ReviewModerationException('السبب مطلوب');
        }

        $notes = trim((string) $notes);

        if ($reason === 'other' && $notes === '') {
            throw new ReviewModerationException('اكتب تفاصيل السبب');
        }

        $text = $reason === 'other' ? $notes : self::REJECT_REASONS[$reason].($notes !== '' ? ': '.$notes : '');

        return Str::limit($text, self::REASON_MAX, '');
    }

    /**
     * يطبّق التعديل المنتظر: القيم والخصائص والصور.
     *
     * @param  list<int>  $removePhotoIds
     */
    private function applyEdit(Review $review, array $removePhotoIds): void
    {
        $payload = (array) $review->pending_payload;
        $photoIds = array_values(array_diff(array_map('intval', (array) ($payload['photo_ids'] ?? [])), $removePhotoIds));

        $review->forceFill([
            'rating' => (int) $payload['rating'],
            'body' => $payload['body'] ?? null,
            'visited_at' => $payload['visited_at'] ?? null,
        ])->save();

        $this->reviews->syncAttributes($review, (array) ($payload['attributes'] ?? []));
        $this->reviews->setPhotos($review, $photoIds);
    }

    /**
     * @param  list<int>  $photoIds
     */
    private function discardPhotos(Review $review, array $photoIds): void
    {
        if ($photoIds === []) {
            return;
        }

        $review->photos()->whereIn('id', $photoIds)->get()
            ->each(fn (ReviewPhoto $photo) => $this->reviews->discardPhoto($photo));
    }

    /**
     * رفض تعديل: صوره اللي لسه ما اتربطتش ملهاش لازمة.
     */
    private function dropPendingPhotos(Review $review): void
    {
        $ids = array_map('intval', (array) ($review->pending_payload['photo_ids'] ?? []));

        if ($ids === []) {
            return;
        }

        ReviewPhoto::query()
            ->where('user_id', $review->user_id)
            ->whereNull('review_id')
            ->whereIn('id', $ids)
            ->get()
            ->each(fn (ReviewPhoto $photo) => $this->reviews->discardPhoto($photo));
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function log(Review $review, User $actor, string $event, array $properties): void
    {
        activity(Review::LOG_NAME)
            ->performedOn($review)
            ->causedBy($actor)
            ->event($event)
            ->withProperties($properties)
            ->log($event);
    }
}
