<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * بارامترات GET /places/{slug} (03 القسم 5.1): `lat` و`lng` اختياريين لحساب `distance_km`،
 * وزوج واحد: الاتنين أو ولا واحد.
 */
class ShowPlaceRequest extends FormRequest
{
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
            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
        ];
    }

    /**
     * وصف البارامترات في توثيق Scribe.
     *
     * @return array<string, array<string, mixed>>
     */
    public function queryParameters(): array
    {
        return [
            'lat' => ['description' => 'خط عرض المستخدم، لحساب `distance_km`. لازم مع `lng`.', 'example' => 30.03],
            'lng' => ['description' => 'خط طول المستخدم، لحساب `distance_km`. لازم مع `lat`.', 'example' => 31.47],
        ];
    }

    public function hasLocation(): bool
    {
        return $this->validated('lat') !== null && $this->validated('lng') !== null;
    }

    public function lat(): float
    {
        return (float) $this->validated('lat');
    }

    public function lng(): float
    {
        return (float) $this->validated('lng');
    }
}
