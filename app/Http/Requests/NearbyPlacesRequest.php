<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * بارامترات GET /places/nearby (03 القسم 5.1): lat وlng إلزاميين، radius بالكم (5، أقصى 30)،
 * limit (10، أقصى 30).
 */
class NearbyPlacesRequest extends FormRequest
{
    public const DEFAULT_RADIUS_KM = 5.0;

    public const MAX_RADIUS_KM = 30.0;

    public const DEFAULT_LIMIT = 10;

    public const MAX_LIMIT = 30;

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
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'radius' => ['nullable', 'numeric', 'min:0.1', 'max:'.self::MAX_RADIUS_KM],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
        ];
    }

    public function lat(): float
    {
        return (float) $this->validated('lat');
    }

    public function lng(): float
    {
        return (float) $this->validated('lng');
    }

    public function radiusKm(): float
    {
        return (float) ($this->validated('radius') ?? self::DEFAULT_RADIUS_KM);
    }

    public function limit(): int
    {
        return (int) ($this->validated('limit') ?? self::DEFAULT_LIMIT);
    }
}
