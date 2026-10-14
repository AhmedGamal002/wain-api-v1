<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * بارامترات GET /home (02 F3، 03 القسم 5.1): `area` إلزامي (slug منطقة فعّالة، نفس اللي `GET /areas` بيرجّعه)،
 * و`lat`/`lng` اختياريين وزوج واحد (من غيرهم «قريب منك» بيرجع فاضي).
 */
class HomeRequest extends FormRequest
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
            // منطقة اتقفلت بعد ما المستخدم اختارها بترجع 422 هنا، فالموبايل بيعرف إنه يطلب اختيار تاني
            'area' => ['required', 'string', Rule::exists('areas', 'slug')->where('is_active', true)],
            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function queryParameters(): array
    {
        return [
            'area' => ['description' => 'slug المنطقة اللي المستخدم اختارها (من `GET /areas`).', 'example' => 'new-cairo'],
            'lat' => ['description' => 'خط العرض. من غيره (ومن غير `lng`) `nearby` بيرجع قايمة فاضية.', 'example' => 30.03],
            'lng' => ['description' => 'خط الطول. لازم مع `lat`.', 'example' => 31.47],
        ];
    }

    public function area(): string
    {
        return (string) $this->validated('area');
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
