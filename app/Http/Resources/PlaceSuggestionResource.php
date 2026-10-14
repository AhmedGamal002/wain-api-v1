<?php

namespace App\Http\Resources;

use App\Models\Place;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * سطر الاقتراح وقت الكتابة (`GET /search/suggestions`): اللي بيتعرض في صف واحد بس (صورة صغيرة والاسم والتصنيف
 * والمنطقة). من غير تقييم ولا سعر ولا مواعيد ولا مسافة، فالرد صغير وبيتحسب من غير حساب «مفتوح دلوقتي».
 *
 * @mixin Place
 */
class PlaceSuggestionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $category = $this->primaryCategory;
        $cover = $this->coverPhoto;

        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->localizedName(),
            'category' => $category === null ? null : [
                'name' => $category->localizedName(),
                'icon' => $category->icon,
            ],
            'area' => $this->area?->localizedName(),
            'thumb' => $cover !== null && $cover->isReady() ? $cover->variantUrl('thumb') : null,
        ];
    }
}
