<?php

namespace App\Http\Requests;

/**
 * بارامترات GET /places/count (03 القسم 5.1): نفس فلاتر `/places` بالظبط. الموبايل بيبعت نفس الـ query
 * اللي بيبعتها للقائمة، فالترتيب والموقع والـ pagination بتتجاهل هنا من غير ما تتسبب في خطأ.
 */
class CountPlacesRequest extends PlaceFilterRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return $this->filterRules();
    }
}
