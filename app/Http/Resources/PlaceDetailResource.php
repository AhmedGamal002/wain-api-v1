<?php

namespace App\Http\Resources;

use App\Models\Attribute;
use App\Models\Place;
use App\Models\PlacePhoto;
use App\Models\Review;
use App\Support\PlaceRating;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * تفاصيل المكان (03 القسم 5.3): كل حقول الكارت + الباقي. مفيش أي حقل داخلي (`internal_notes`،
 * `status_reason`، `created_by`، `updated_by`، `claimed_by`) ولا مسار الصورة الأصلية.
 *
 * - المنشور والمغلق نهائياً بس هو اللي بيوصل هنا (`Place::viewable`).
 * - المغلق نهائياً (`status = closed`): `is_open_now` و`hours` من غير حالة (مفيش «مفتوح دلوقتي» لمكان قفل)،
 *   والموبايل بيخفي أزرار التواصل (03 القسم 6.5).
 * - `latest_reviews` أحدث 5 تقييمات منشورة و`rating_distribution` توزيع النجوم (F8). `is_saved` false لحد F9.
 *
 * @mixin Place
 */
class PlaceDetailResource extends JsonResource
{
    /** بلوك `hours` لمكان مغلق نهائياً: نفس الشكل، من غير حالة ولا مواعيد. */
    private const CLOSED_HOURS = [
        'is_open_now' => null,
        'state' => null,
        'status_text' => null,
        'is_ramadan' => false,
        'week' => [],
    ];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Place $place */
        $place = $this->resource;
        $closed = $place->status === 'closed';
        $card = (new PlaceCardResource($place))->toArray($request);

        return [
            ...$card,
            'is_open_now' => $closed ? null : $card['is_open_now'],
            'status' => $place->status,
            'description' => $place->localizedDescription(),
            'address' => $place->localizedAddress(),
            'categories' => $this->categoryList($place),
            'attributes' => $this->attributeGroups($place),
            'contact' => [
                'phone' => $place->phone,
                'whatsapp' => $place->whatsapp,
                'instagram' => $place->instagram,
                'facebook_url' => $place->facebook_url,
                'website' => $place->website,
                'menu_url' => $place->menu_url,
            ],
            'hours' => $closed ? self::CLOSED_HOURS : (new PlaceHoursResource($place))->toArray($request),
            'photos' => $this->photoList($place, 'gallery'),
            'menu_photos' => $this->photoList($place, 'menu'),
            'closed_at' => $place->closed_at?->toDateString(),
            'verified_at' => $place->verified_at?->timezone('Africa/Cairo')->toDateString(),
            'is_claimed' => $place->claimed_by !== null,
            'share_url' => config('wain.share_base_url').'/'.$place->slug,
            'rating_distribution' => PlaceRating::distribution($place->id),
            'latest_reviews' => $this->latestReviews($place, $request),
        ];
    }

    /**
     * أحدث 5 تقييمات منشورة (صفحة المكان، F7/F8). تقييم المستخدم نفسه بأي حالة في `GET /places/{slug}/reviews`
     * (`mine`)، مش هنا.
     *
     * @return list<array<string, mixed>>
     */
    private function latestReviews(Place $place, Request $request): array
    {
        $reviews = Review::query()
            ->published()
            ->where('place_id', $place->id)
            ->with(['user', 'reviewAttributes', 'photos'])
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit(5)
            ->get();

        return ReviewResource::collection($reviews)->resolve($request);
    }

    /**
     * التصنيفات: الرئيسي الأول، وبعده باقي التصنيفات بترتيب الـ sort_order.
     *
     * @return list<array{slug: string, name: string}>
     */
    private function categoryList(Place $place): array
    {
        return $place->categories
            ->sortBy([
                fn ($a, $b) => ($b->id === $place->primary_category_id) <=> ($a->id === $place->primary_category_id),
                ['sort_order', 'asc'],
                ['id', 'asc'],
            ])
            ->map(fn ($category) => ['slug' => $category->slug, 'name' => $category->localizedName()])
            ->values()
            ->all();
    }

    /**
     * الخصائص الفعّالة متجمّعة بالمجموعات بترتيب العرض (الجو، الخدمات، مناسب لـ، المكان)، والمجموعة
     * الفاضية بتختفي. اسم المجموعة بلغة الطلب.
     *
     * @return list<array{group: string, items: list<array{slug: string, name: string, icon: string}>}>
     */
    private function attributeGroups(Place $place): array
    {
        /** @var Collection<string, Collection<int, Attribute>> $byGroup */
        $byGroup = $place->placeAttributes
            ->where('is_active', true)
            ->sortBy([['sort_order', 'asc'], ['id', 'asc']])
            ->groupBy('group');

        $groups = [];
        foreach (Attribute::GROUPS as $group) {
            $items = $byGroup->get($group);
            if ($items === null || $items->isEmpty()) {
                continue;
            }

            $groups[] = [
                'group' => __('places.attribute_groups.'.$group),
                'items' => $items->map(fn (Attribute $attribute) => [
                    'slug' => $attribute->slug,
                    'name' => $attribute->localizedName(),
                    'icon' => $attribute->icon,
                ])->values()->all(),
            ];
        }

        return $groups;
    }

    /**
     * صور الـ `ready` بس من النوع المطلوب، والغلاف الأول في المعرض. الأصل ما بيطلعش، بس `medium` و`large`.
     *
     * @return list<array{id: int, medium: string, large: string, caption: string|null}>
     */
    private function photoList(Place $place, string $type): array
    {
        return $place->photos
            ->where('type', $type)
            ->filter(fn (PlacePhoto $photo) => $photo->isReady()
                && $photo->variantUrl('medium') !== null
                && $photo->variantUrl('large') !== null)
            ->sortBy([
                fn ($a, $b) => ($b->id === $place->cover_photo_id) <=> ($a->id === $place->cover_photo_id),
                ['sort_order', 'asc'],
                ['id', 'asc'],
            ])
            ->map(fn (PlacePhoto $photo) => [
                'id' => $photo->id,
                'medium' => $photo->variantUrl('medium'),
                'large' => $photo->variantUrl('large'),
                'caption' => $photo->localizedCaption(),
            ])
            ->values()
            ->all();
    }
}
