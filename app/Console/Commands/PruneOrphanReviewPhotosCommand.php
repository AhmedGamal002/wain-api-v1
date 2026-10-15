<?php

namespace App\Console\Commands;

use App\Models\Review;
use App\Models\ReviewPhoto;
use App\Support\ReviewService;
use Illuminate\Console\Command;

/**
 * يمسح صور التقييمات اللي اترفعت وما اتربطتش بتقييم بعد 24 ساعة (06 القسم 2.3 `review_photos`): مستخدم رفع وساب
 * الشاشة. الصورة اللي في `pending_payload` بتاع تعديل لسه مستني قرار بتفضل (هي لسه ما اتربطتش بس مستخدمة).
 */
class PruneOrphanReviewPhotosCommand extends Command
{
    protected $signature = 'reviews:prune-photos {--hours=24 : عمر الصورة اللي ما اتربطتش بالساعات}';

    protected $description = 'Delete review photos that were uploaded but never attached to a review';

    public function handle(ReviewService $reviews): int
    {
        $held = Review::query()
            ->where('edit_status', Review::EDIT_PENDING)
            ->whereNotNull('pending_payload')
            ->get(['id', 'pending_payload'])
            ->flatMap(fn (Review $review) => (array) ($review->pending_payload['photo_ids'] ?? []))
            ->map(fn ($id) => (int) $id)
            ->all();

        $removed = 0;

        ReviewPhoto::query()
            ->whereNull('review_id')
            ->where('created_at', '<', now()->subHours((int) $this->option('hours')))
            ->when($held !== [], fn ($query) => $query->whereNotIn('id', $held))
            ->orderBy('id')
            ->each(function (ReviewPhoto $photo) use ($reviews, &$removed): void {
                $reviews->discardPhoto($photo);
                $removed++;
            });

        $this->info("Removed {$removed} unattached review photo(s).");

        return self::SUCCESS;
    }
}
