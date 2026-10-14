<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * بارامترات `GET /search/suggestions` (02 F4): كلمة الكتابة `q` بس. الاقتراحات مش بتتفلتر بفلاتر الجلسة (زي
 * اقتراحات أي تطبيق خرائط): الفلاتر بتتطبّق على نتايج `GET /search` لما المستخدم يدوس بحث.
 */
class SearchSuggestionsRequest extends FormRequest
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
            'q' => ['required', 'string', 'min:'.SearchPlacesRequest::MIN_LENGTH, 'max:'.SearchPlacesRequest::MAX_LENGTH],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function queryParameters(): array
    {
        return [
            'q' => [
                'description' => 'اللي المستخدم كتبه لحد دلوقتي (حتى لو كلمة ناقصة)، من حرفين لـ 60 حرف. نفس التطبيع والمرادفات '
                    .'والتصحيح بتاعة `GET /search`.',
                'example' => 'حدي',
            ],
        ];
    }

    public function searchQuery(): string
    {
        return (string) $this->validated('q');
    }
}
