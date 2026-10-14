<?php

namespace App\Http\Requests;

use App\Models\PlaceEvent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /places/{slug}/events` (03 القسم 5.1). النوع من الـ body، والجهاز من هيدر `X-Device-Id` (إلزامي هنا
 * بس، 06 القسم 1): من غيره الحدث ما بيتحسبش لجهاز ومشاهدة نفس الشخص كانت هتتعدّ كل مرة. الهيدر بيتتحقق منه
 * كـ UUID تحت اسم `device_id`، فالرفض بيرجع 422 بنفس شكل الأخطاء الباقية.
 */
class RecordPlaceEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['device_id' => trim((string) $this->header('X-Device-Id'))]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in(PlaceEvent::CLIENT_TYPES)],
            'device_id' => ['required', 'uuid'],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function bodyParameters(): array
    {
        return [
            'type' => [
                'description' => 'نوع الحدث: `view` (فتح الصفحة، مرة لكل فتحة)، `directions`، `call`، `whatsapp`، `instagram`، `menu`، `share`. '
                    .'`save` بيتسجل من السيرفر مع المفضلة فمش مقبول هنا.',
                'example' => 'directions',
            ],
        ];
    }

    public function eventType(): string
    {
        return (string) $this->validated('type');
    }

    /** UUID صغير الحروف، زي ما بيتخزن في `search_logs`. */
    public function deviceId(): string
    {
        return strtolower((string) $this->validated('device_id'));
    }
}
