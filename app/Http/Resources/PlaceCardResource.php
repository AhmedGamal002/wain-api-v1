<?php

namespace App\Http\Resources;

use App\Models\Place;
use App\Support\OpenNow;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * كارت المكان في كل القوائم (03 القسم 5.2). مفيش أي حقل داخلي (internal_notes، status_reason،
 * created_by...) ولا مسار الصورة الأصلية.
 *
 * `distance_km` بيتحسب في الاستعلام (`distance_m`) ويرجع null من غير lat/lng.
 * `is_open_now` بيتحسب بتوقيت القاهرة (null لو المواعيد فاضية). `is_saved` false لحد F9 (أسبوع 11).
 *
 * @mixin Place
 */
class PlaceCardResource extends JsonResource
{
    /** اسم attribute الـ request اللي الـ controller بيحط فيه لحظة «دلوقتي» الواحدة للطلب */
    public const OPEN_NOW_ATTRIBUTE = 'open_now';

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $category = $this->primaryCategory;
        $cover = $this->coverPhoto;
        $hasPrice = $this->price_min !== null || $this->price_max !== null;

        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->localizedName(),
            'primary_category' => $category === null ? null : [
                'slug' => $category->slug,
                'name' => $category->localizedName(),
                'icon' => $category->icon,
                'color' => $category->color,
            ],
            'area' => $this->area === null ? null : [
                'slug' => $this->area->slug,
                'name' => $this->area->localizedName(),
            ],
            'cover' => $cover !== null && $cover->isReady() ? [
                'thumb' => $cover->variantUrl('thumb'),
                'medium' => $cover->variantUrl('medium'),
            ] : null,
            'price' => $hasPrice ? [
                'min' => $this->price_min,
                'max' => $this->price_max,
                'range' => $this->price_range,
            ] : null,
            'rating' => [
                'avg' => round($this->rating_avg, 1),
                'count' => $this->rating_count,
            ],
            'listing_tier' => $this->listing_tier,
            'is_open_now' => $this->openNow($request)->isOpen($this->resource),
            'distance_km' => isset($this->distance_m) ? round($this->distance_m / 1000, 1) : null,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'is_saved' => false,
        ];
    }

    private function openNow(Request $request): OpenNow
    {
        return $request->attributes->get(self::OPEN_NOW_ATTRIBUTE) ?? OpenNow::at();
    }
}
