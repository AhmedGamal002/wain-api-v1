<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * بارامترات GET /places (03 القسم 5.1). الفلاتر (التصنيفات والخصائص والسعر والتقييم و open_now)
 * بتتضاف مع F5 في أسبوع 4.
 */
class IndexPlacesRequest extends FormRequest
{
    public const SORTS = ['recommended', 'nearest', 'rating', 'newest'];

    public const DEFAULT_PER_PAGE = 20;

    public const MAX_PER_PAGE = 50;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'area' => ['nullable', 'string', Rule::exists('areas', 'slug')],
            // التصنيفات بـ OR (F5): المكان يظهر لو أي تصنيف من تصنيفاته مطابق
            'categories' => ['nullable', 'array', 'max:8'],
            'categories.*' => ['string', Rule::exists('categories', 'slug')],
            'sort' => ['nullable', Rule::in(self::SORTS)],
            // الموقع زوج واحد: الاتنين أو ولا واحد
            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:lng', 'required_if:sort,nearest'],
            'lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat', 'required_if:sort,nearest'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
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
