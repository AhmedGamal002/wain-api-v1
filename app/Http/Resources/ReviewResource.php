<?php

namespace App\Http\Resources;

use App\Models\Review;
use App\Models\ReviewPhoto;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * التقييم في الـ API (06 القسم 3). للناس: النجوم والرأي والخصائص والصور الجاهزة واسم المقيِّم وتاريخ النشر.
 * المستخدم المحذوف بيظهر `user.name = null` و`is_deleted = true` (التطبيق بيكتب «مستخدم محذوف»).
 *
 * لصاحب التقييم بس (`is_mine`): `status` و`status_reason` (سبب الرفض) و`edit` (تعديل منتظر أو مرفوض بقيمه)،
 * والصور اللي لسه بتتعالج. مفيش حقل داخلي (`moderated_by` ولا `reports_count`) في أي حالة.
 *
 * @mixin Review
 */
class ReviewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Review $review */
        $review = $this->resource;
        $viewer = $request->user('sanctum');
        $mine = $viewer instanceof User && $viewer->id === $review->user_id;

        $data = [
            'id' => $review->id,
            'rating' => $review->rating,
            'body' => $review->body,
            'visited_at' => $review->visited_at?->format('Y-m'),
            'attributes' => $review->reviewAttributes->map(fn ($attribute) => [
                'slug' => $attribute->slug,
                'name' => $attribute->localizedName(),
                'icon' => $attribute->icon,
            ])->values()->all(),
            'photos' => $review->photos
                ->filter(fn (ReviewPhoto $photo) => $mine || $photo->isReady())
                ->map(fn (ReviewPhoto $photo) => $this->photo($photo))
                ->values()
                ->all(),
            'user' => $this->reviewer($review),
            'published_at' => $review->published_at?->utc()->toIso8601ZuluString(),
            'is_mine' => $mine,
        ];

        if ($mine) {
            $data['status'] = $review->status;
            $data['status_reason'] = $review->status === Review::STATUS_REJECTED ? $review->status_reason : null;
            $data['edit'] = $review->edit_status === Review::EDIT_NONE ? null : [
                'status' => $review->edit_status,
                'reason' => $review->edit_status === Review::EDIT_REJECTED ? $review->edit_reason : null,
                'payload' => $review->edit_status === Review::EDIT_PENDING ? $review->pending_payload : null,
            ];
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function photo(ReviewPhoto $photo): array
    {
        return [
            'id' => $photo->id,
            'status' => $photo->status,
            'thumb' => $photo->variantUrl('thumb'),
            'medium' => $photo->variantUrl('medium'),
            'large' => $photo->variantUrl('large'),
        ];
    }

    /**
     * @return array{name: string|null, avatar_url: string|null, is_deleted: bool}
     */
    private function reviewer(Review $review): array
    {
        $user = $review->user;

        // حساب اتحذف (soft delete) أو اتمسحت بياناته: التقييم بيفضل منشور من غير اسم ولا صورة
        if ($user === null || $user->trashed() || $user->anonymized_at !== null) {
            return ['name' => null, 'avatar_url' => null, 'is_deleted' => true];
        }

        return [
            'name' => $user->name,
            'avatar_url' => $user->avatar_path ? Storage::url($user->avatar_path) : null,
            'is_deleted' => false,
        ];
    }
}
