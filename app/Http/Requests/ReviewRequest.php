<?php

namespace App\Http\Requests;

use App\Rules\NoContactDetails;
use App\Support\ReviewService;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * form التقييم (02 F8): أساس `StoreReviewRequest` و`UpdateReviewRequest` (نفس الحقول والقواعد).
 *
 * النجوم 1–5 (إلزامي)، والرأي 10–1000 حرف لو اتكتب وممنوع فيه أرقام تليفونات وروابط، والخصائص (slugs من A3)
 * لحد 5، والصور (ids من `POST /uploads/photos`، بتاعة المستخدم وغير مربوطة بتقييم تاني) لحد 5، وشهر الزيارة
 * `YYYY-MM` مش في المستقبل.
 */
abstract class ReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $body = $this->input('body');

        if (is_string($body)) {
            $body = trim($body);
            $this->merge(['body' => $body === '' ? null : $body]);
        }
    }

    /**
     * التقييم اللي بيتعدّل (علشان صوره المربوطة بيه تفضل مسموحة)، أو null للجديد.
     */
    protected function reviewId(): ?int
    {
        return null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $userId = $this->user('sanctum')?->getAuthIdentifier();
        $reviewId = $this->reviewId();

        return [
            'rating' => ['required', 'integer', 'between:1,5'],
            'body' => ['nullable', 'string', 'min:10', 'max:1000', new NoContactDetails],
            'visited_at' => [
                'nullable',
                'date_format:Y-m',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_string($value) && $this->visitedMonth($value)?->isFuture() === true) {
                        $fail('validation.before_or_equal')->translate(['date' => now('Africa/Cairo')->format('Y-m')]);
                    }
                },
            ],
            'attributes' => ['nullable', 'array', 'max:'.ReviewService::MAX_ATTRIBUTES],
            'attributes.*' => ['string', 'distinct', Rule::exists('attributes', 'slug')->where('is_active', true)],
            'photo_ids' => ['nullable', 'array', 'max:'.ReviewService::MAX_PHOTOS],
            'photo_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('review_photos', 'id')
                    ->where('user_id', $userId)
                    ->where(fn ($query) => $query->whereNull('review_id')->when(
                        $reviewId !== null,
                        fn ($inner) => $inner->orWhere('review_id', $reviewId),
                    )),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'rating' => __('reviews.fields.rating'),
            'body' => __('reviews.fields.body'),
            'visited_at' => __('reviews.fields.visited_at'),
            'attributes' => __('reviews.fields.attributes'),
            'photo_ids' => __('reviews.fields.photo_ids'),
        ];
    }

    /**
     * القيم متطبّعة للـ `ReviewService`: النص null لو فاضي، وشهر الزيارة أول يوم فيه.
     *
     * @return array{rating: int, body: string|null, visited_at: CarbonImmutable|null, attributes: list<string>, photo_ids: list<int>}
     */
    public function reviewData(): array
    {
        $visited = $this->validated('visited_at');

        return [
            'rating' => (int) $this->validated('rating'),
            'body' => $this->validated('body'),
            'visited_at' => is_string($visited) ? $this->visitedMonth($visited) : null,
            'attributes' => array_values((array) ($this->validated('attributes') ?? [])),
            'photo_ids' => array_map('intval', array_values((array) ($this->validated('photo_ids') ?? []))),
        ];
    }

    private function visitedMonth(string $value): ?CarbonImmutable
    {
        try {
            return CarbonImmutable::createFromFormat('!Y-m', $value, 'Africa/Cairo')?->startOfMonth();
        } catch (Throwable) {
            return null;
        }
    }
}
