<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * بارامترات GET /places (03 القسم 5.1): الفلاتر المشتركة (PlaceFilterRequest) + الترتيب والموقع والـ pagination.
 */
class IndexPlacesRequest extends PlaceFilterRequest
{
    public const SORTS = ['recommended', 'nearest', 'rating', 'newest'];

    public const DEFAULT_PER_PAGE = 20;

    public const MAX_PER_PAGE = 50;

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            ...$this->filterRules(),
            'sort' => ['nullable', Rule::in(self::SORTS)],
            // الموقع زوج واحد: الاتنين أو ولا واحد
            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:lng', 'required_if:sort,nearest'],
            'lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat', 'required_if:sort,nearest'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ];
    }

    public function queryParameters(): array
    {
        return [
            ...parent::queryParameters(),
            'sort' => ['description' => 'الترتيب: `recommended` (الافتراضي) أو `nearest` (محتاج `lat` و`lng`) أو `rating` أو `newest`.', 'example' => 'recommended'],
            'lat' => ['description' => 'خط العرض. بيرجّع `distance_km` في كل كارت. لازم مع `lng`.', 'example' => 30.03],
            'lng' => ['description' => 'خط الطول. لازم مع `lat`.', 'example' => 31.47],
            'page' => ['description' => 'رقم الصفحة (من 1).', 'example' => 1],
            'per_page' => ['description' => 'عدد النتايج في الصفحة (20، أقصى 50).', 'example' => 20],
        ];
    }

    public function sort(): string
    {
        return $this->validated('sort') ?? 'recommended';
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? self::DEFAULT_PER_PAGE);
    }

    public function hasLocation(): bool
    {
        return $this->validated('lat') !== null && $this->validated('lng') !== null;
    }
}
