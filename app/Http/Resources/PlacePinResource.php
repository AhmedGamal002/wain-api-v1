<?php

namespace App\Http\Resources;

use App\Models\Place;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * pin الخريطة (03 القسم 5.2): الحد الأدنى اللي الموبايل يرسم بيه علامة. الكارت بيتحمّل لما المستخدم يدوس على
 * الـ pin (`GET /places/{slug}`)، فمفيش اسم ولا صورة ولا مواعيد هنا.
 *
 * `category_slug` بيتحوّل للون من `GET /categories`، و`listing_tier` بيحدد حجم الـ pin.
 *
 * @mixin Place
 */
class PlacePinResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'category_slug' => $this->primaryCategory?->slug,
            'listing_tier' => $this->listing_tier,
        ];
    }
}
