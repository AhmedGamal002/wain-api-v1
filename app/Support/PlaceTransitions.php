<?php

namespace App\Support;

use App\Models\Place;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * انتقالات حالة المكان (03 القسم 2 «جدول الانتقالات»): مكان واحد للقواعد، والـ UI (الجدول وصفحة التعديل
 * والإجراءات الجماعية) بينادي عليه بس. كل انتقال بيتحقق من:
 *   1. الصلاحية (`PlacePolicy`): عشان الطلب المباشر والجماعي يلتزموا بيها زي الزرار.
 *   2. الحالة الحالية.
 *   3. شروط النشر (`PlacePublishCheck`) لو الانتقال بيخلّي المكان ظاهر أو مستني مراجعة.
 *
 * الحفظ بالموديل (مش `DB::table`)، فالـ Observer بيملى المشتق (`published_at`، رجوع الباقة free عند القفل)
 * وبيطلق `PlaceChanged`، وسجل التغييرات بيسجّل الحالة والسبب بالقيمة القديمة والجديدة.
 *
 * بيرمي `PlaceTransitionException` برسالة عربي جاهزة للعرض، وأي رفض صلاحية `AuthorizationException`.
 */
final class PlaceTransitions
{
    /** أسباب الإخفاء (03 القسم 4.6). `other` بيطلب تفاصيل. */
    public const HIDE_REASONS = [
        'needs_review' => 'بيانات محتاجة مراجعة',
        'renovation' => 'تجديدات مؤقتة',
        'complaint' => 'شكوى',
        'owner_request' => 'طلب صاحب المكان',
        'other' => 'تاني',
    ];

    /** أسباب الرفض (03 القسم 4.6). */
    public const REJECT_REASONS = [
        'missing_data' => 'بيانات ناقصة',
        'weak_photos' => 'صور ضعيفة',
        'duplicate' => 'مكرر',
        'not_suitable' => 'مش مناسب لـ Wain',
        'other' => 'تاني',
    ];

    public const TIERS = ['free', 'featured', 'premium'];

    /** `status_reason` عمود VARCHAR(255). */
    private const REASON_MAX = 255;

    /** إرسال للمراجعة (مسودة ← قيد المراجعة). */
    public function submit(Place $place, User $actor): Place
    {
        $this->authorize($actor, 'submit', $place);
        $this->requireStatus($place, [Place::STATUS_DRAFT], 'الإرسال للمراجعة للمسودة بس');
        $this->requireComplete($place, 'ما ينفعش يتبعت للمراجعة. ناقص: ');

        return $this->apply($place, ['status' => Place::STATUS_PENDING, 'status_reason' => null]);
    }

    /** نشر (مسودة أو قيد المراجعة ← منشور). */
    public function publish(Place $place, User $actor): Place
    {
        $this->authorize($actor, 'publish', $place);
        $this->requireStatus($place, [Place::STATUS_DRAFT, Place::STATUS_PENDING], 'النشر لمسودة أو مكان قيد المراجعة بس');
        $this->requireComplete($place);

        return $this->apply($place, ['status' => Place::STATUS_ACTIVE, 'status_reason' => null]);
    }

    /** رفض (قيد المراجعة ← مرفوض)، والسبب إلزامي. */
    public function reject(Place $place, User $actor, string $reason, ?string $notes = null): Place
    {
        $this->authorize($actor, 'reject', $place);
        $this->requireStatus($place, [Place::STATUS_PENDING], 'الرفض للمكان قيد المراجعة بس');

        return $this->apply($place, [
            'status' => Place::STATUS_REJECTED,
            'status_reason' => $this->reasonText(self::REJECT_REASONS, $reason, $notes),
        ]);
    }

    /** إخفاء (منشور ← مخفي)، والسبب إلزامي. */
    public function hide(Place $place, User $actor, string $reason, ?string $details = null): Place
    {
        $this->authorize($actor, 'hide', $place);
        $this->requireStatus($place, [Place::STATUS_ACTIVE], 'الإخفاء للمكان المنشور بس');

        return $this->apply($place, [
            'status' => Place::STATUS_HIDDEN,
            'status_reason' => $this->reasonText(self::HIDE_REASONS, $reason, $details),
        ]);
    }

    /** إظهار (مخفي ← منشور). */
    public function show(Place $place, User $actor): Place
    {
        $this->authorize($actor, 'show', $place);
        $this->requireStatus($place, [Place::STATUS_HIDDEN], 'الإظهار للمكان المخفي بس');
        $this->requireComplete($place);

        return $this->apply($place, ['status' => Place::STATUS_ACTIVE, 'status_reason' => null]);
    }

    /**
     * قفل نهائي (منشور أو مخفي ← مغلق). التاريخ إلزامي ومش في المستقبل. الـ Observer بيرجّع الباقة free.
     */
    public function close(Place $place, User $actor, CarbonInterface $closedAt, ?string $note = null): Place
    {
        $this->authorize($actor, 'close', $place);
        $this->requireStatus($place, [Place::STATUS_ACTIVE, Place::STATUS_HIDDEN], 'القفل النهائي للمكان المنشور أو المخفي بس');

        // مقارنة تواريخ (مش لحظات): «النهارده» بتوقيت القاهرة
        if ($closedAt->toDateString() > now(self::tz())->toDateString()) {
            throw new PlaceTransitionException('تاريخ القفل مش ممكن يكون في المستقبل');
        }

        return $this->apply($place, [
            'status' => Place::STATUS_CLOSED,
            'status_reason' => filled($note) ? Str::limit(trim((string) $note), self::REASON_MAX, '') : null,
            'closed_at' => $closedAt->toDateString(),
        ]);
    }

    /** إعادة فتح (مغلق نهائياً ← منشور). */
    public function reopen(Place $place, User $actor): Place
    {
        $this->authorize($actor, 'publish', $place);
        $this->requireStatus($place, [Place::STATUS_CLOSED], 'إعادة الفتح للمكان المغلق نهائياً بس');
        $this->requireComplete($place);

        return $this->apply($place, ['status' => Place::STATUS_ACTIVE, 'status_reason' => null, 'closed_at' => null]);
    }

    /**
     * تغيير الباقة (للمنشور بس). `free` بيصفّر التواريخ، والمدفوعة بتطلب التاريخين و«إلى» بعد «من».
     * `$note` (رقم الفاتورة) بيتسجّل في سجل التغييرات مش في عمود.
     */
    public function setTier(Place $place, User $actor, string $tier, ?CarbonInterface $from = null, ?CarbonInterface $to = null, ?string $note = null): Place
    {
        $this->authorize($actor, 'setTier', $place);
        $this->requireStatus($place, [Place::STATUS_ACTIVE], 'الباقة للمكان المنشور بس');

        if (! in_array($tier, self::TIERS, true)) {
            throw new PlaceTransitionException('الباقة مش معروفة');
        }

        if ($tier === 'free') {
            $attributes = ['listing_tier' => 'free', 'tier_starts_at' => null, 'tier_ends_at' => null];
        } else {
            if ($from === null || $to === null) {
                throw new PlaceTransitionException('تاريخ بداية ونهاية الباقة إلزاميين');
            }
            if ($to->toDateString() <= $from->toDateString()) {
                throw new PlaceTransitionException('نهاية الباقة لازم تبقى بعد بدايتها');
            }

            // التاريخين يوم كامل بتوقيت القاهرة، والتخزين UTC
            $attributes = [
                'listing_tier' => $tier,
                'tier_starts_at' => $from->copy()->shiftTimezone(self::tz())->startOfDay()->utc(),
                'tier_ends_at' => $to->copy()->shiftTimezone(self::tz())->endOfDay()->utc(),
            ];
        }

        $place->logNote = filled($note) ? trim((string) $note) : null;

        return $this->apply($place, $attributes);
    }

    /** «أكّدت البيانات النهارده». */
    public function verify(Place $place, User $actor): Place
    {
        $this->authorize($actor, 'update', $place);

        return $this->apply($place, ['verified_at' => now()]);
    }

    /** حذف (soft delete). الـ job بيمسحه نهائياً بعد 30 يوم (أسبوع 7). */
    public function delete(Place $place, User $actor): void
    {
        $this->authorize($actor, 'delete', $place);

        if ($place->trashed()) {
            throw new PlaceTransitionException('المكان محذوف أصلاً');
        }

        $place->delete();
    }

    /** استرجاع خلال 30 يوم: بيرجع «مخفي» عشان حد يراجعه قبل ما يظهر (حفظ واحد مع `deleted_at`). */
    public function restore(Place $place, User $actor): Place
    {
        $this->authorize($actor, 'restore', $place);

        if (! $place->trashed()) {
            throw new PlaceTransitionException('المكان مش محذوف');
        }

        if ($place->deleted_at->lt(now()->subDays(Place::PURGE_AFTER_DAYS))) {
            throw new PlaceTransitionException('فات على الحذف أكتر من '.Place::PURGE_AFTER_DAYS.' يوم، المكان ما بقاش ينفع يتسترجع');
        }

        $place->status = Place::STATUS_HIDDEN;
        $place->status_reason = 'اتسترجع من المحذوفات: محتاج مراجعة قبل الظهور';
        $place->restore();

        return $place;
    }

    /**
     * نشر جماعي: بيعدّي الناقص ويكمّل الباقي. بيرجّع [اتنشر، اتعدّى] وأسماء المتعدّي مع الناقص.
     *
     * @param  iterable<Place>  $places
     * @return array{published: int, skipped: list<array{place: Place, missing: list<string>}>}
     */
    public function publishMany(iterable $places, User $actor): array
    {
        $published = 0;
        $skipped = [];

        foreach ($places as $place) {
            try {
                $this->publish($place, $actor);
                $published++;
            } catch (PlaceTransitionException) {
                $result = PlacePublishCheck::for($place);
                $skipped[] = ['place' => $place, 'missing' => $result->missingLabels()];
            }
        }

        return ['published' => $published, 'skipped' => $skipped];
    }

    // ───────────────────────── داخلي ─────────────────────────

    /** @param  array<string, mixed>  $attributes */
    private function apply(Place $place, array $attributes): Place
    {
        DB::transaction(function () use ($place, $attributes) {
            $place->forceFill($attributes)->save();
        });

        $place->logNote = null;

        return $place;
    }

    private function authorize(User $actor, string $ability, Place $place): void
    {
        Gate::forUser($actor)->authorize($ability, $place);
    }

    /** @param  list<string>  $allowed */
    private function requireStatus(Place $place, array $allowed, string $message): void
    {
        if ($place->trashed()) {
            throw new PlaceTransitionException('المكان محذوف: استرجعه الأول');
        }

        if (! in_array($place->status, $allowed, true)) {
            throw new PlaceTransitionException($message.' (حالته دلوقتي: '.(Place::STATUS_LABELS[$place->status] ?? $place->status).')');
        }
    }

    private function requireComplete(Place $place, string $prefix = 'ما ينفعش يتنشر. ناقص: '): void
    {
        $result = PlacePublishCheck::for($place);

        if (! $result->isComplete()) {
            throw new PlaceTransitionException($prefix.implode('، ', $result->missingLabels()));
        }
    }

    /**
     * نص السبب المخزّن في `status_reason`: اسم السبب + التفاصيل. السبب إلزامي، والتفاصيل إلزامية مع «تاني».
     *
     * @param  array<string, string>  $reasons
     */
    private function reasonText(array $reasons, string $key, ?string $details): string
    {
        if (! isset($reasons[$key])) {
            throw new PlaceTransitionException('السبب إلزامي');
        }

        $details = trim((string) $details);

        if ($key === 'other' && $details === '') {
            throw new PlaceTransitionException('اكتب التفاصيل لما السبب «تاني»');
        }

        $text = $reasons[$key].($details !== '' ? ': '.$details : '');

        return Str::limit($text, self::REASON_MAX, '');
    }

    private static function tz(): string
    {
        return 'Africa/Cairo';
    }
}
