<?php

namespace App\Models;

use Database\Factories\ReviewFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * تقييم مكان (F8، 06 القسم 2.3). بيدخل `pending`، ويظهر في المكان بعد موافقة الفريق (A6). تعديل تقييم منشور
 * بيفضل ظاهر بقيمه القديمة (ومحسوب في المتوسط) و`pending_payload` فيه النسخة الجديدة لحد الموافقة.
 * القواعد والانتقالات في `App\Support\ReviewService` (المستخدم) و`App\Support\ReviewModeration` (الفريق).
 */
class Review extends Model
{
    /** @use HasFactory<ReviewFactory> */
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_HIDDEN = 'hidden';

    public const EDIT_NONE = 'none';

    public const EDIT_PENDING = 'pending';

    public const EDIT_REJECTED = 'rejected';

    /** اسم سجل التغييرات (activitylog) للتقييمات. */
    public const LOG_NAME = 'reviews';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'visited_at' => 'date',
            'pending_payload' => 'array',
            'reports_count' => 'integer',
            'published_at' => 'datetime',
            'submitted_at' => 'datetime',
            'moderated_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Place, $this> */
    public function place(): BelongsTo
    {
        return $this->belongsTo(Place::class)->withTrashed();
    }

    /**
     * صاحب التقييم، حتى لو حسابه اتحذف (soft delete): التقييم بيفضل منشور باسم «مستخدم محذوف».
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function moderator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moderated_by')->withTrashed();
    }

    /**
     * الخصائص اللي المقيِّم لاحظها (من خصائص A3، لحد 5).
     *
     * @return BelongsToMany<Attribute, $this>
     */
    public function reviewAttributes(): BelongsToMany
    {
        return $this->belongsToMany(Attribute::class, 'review_attributes');
    }

    /** @return HasMany<ReviewPhoto, $this> */
    public function photos(): HasMany
    {
        return $this->hasMany(ReviewPhoto::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * المنشور: اللي بيظهر للناس وبيتحسب في المتوسط (06: `rating_avg` من `status = published` بالقيم المنشورة).
     *
     * @param  Builder<Review>  $query
     */
    public function scopePublished(Builder $query): void
    {
        $query->where('reviews.status', self::STATUS_PUBLISHED);
    }

    /**
     * اللي مستني قرار في طابور المراجعة: تقييم جديد، أو تعديل على منشور.
     *
     * @param  Builder<Review>  $query
     */
    public function scopeAwaitingDecision(Builder $query): void
    {
        $query->where(fn (Builder $inner) => $inner
            ->where('reviews.status', self::STATUS_PENDING)
            ->orWhere('reviews.edit_status', self::EDIT_PENDING));
    }

    /** تقييم جديد لسه ما اتنشرش (مش تعديل). */
    public function isNewSubmission(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function hasPendingEdit(): bool
    {
        return $this->edit_status === self::EDIT_PENDING && is_array($this->pending_payload);
    }
}
